/*
 SPDX-FileCopyrightText: © 2026 Fossology contributors

 SPDX-License-Identifier: GPL-2.0-only
*/

#include "DatabaseHandler.hpp"

#include "libfossUtils.hpp"

DatabaseHandler::DatabaseHandler(fo::DbManager dbManager) :
  fo::AgentDatabaseHandler(std::move(dbManager))
{
}

DatabaseHandler DatabaseHandler::spawn() const
{
  return DatabaseHandler(this->dbManager.spawn());
}

bool DatabaseHandler::queryLatestReuseTarget(int uploadId, int groupId,
  UploadReusePair& out)
{
  auto result = dbManager.execPrepared(
    fo_dbManager_PrepareStamement(dbManager.getStruct_dbManager(), __func__,
      "SELECT reused_upload_fk, reused_group_fk FROM upload_reuse WHERE upload_fk=$1 "
      "AND group_fk=$2 ORDER BY date_added DESC LIMIT 1",
      int, int),
    uploadId, groupId);

  if (result.getRowCount() < 1)
  {
    return false;
  }
  auto row = result.getRow(0);
  out.reusedUploadId = static_cast<int>(fo::stringToUnsignedLong(row[0].c_str()));
  out.reusedGroupId = static_cast<int>(fo::stringToUnsignedLong(row[1].c_str()));
  return true;
}

long DatabaseHandler::createAnalysis(int uploadId, int reusedUploadId, int groupId)
{
  auto result = dbManager.execPrepared(
    fo_dbManager_PrepareStamement(dbManager.getStruct_dbManager(), __func__,
      "INSERT INTO enhanced_reuse_analysis(upload_fk, reused_upload_fk, group_fk, status) "
      "VALUES($1, $2, $3, 'RUNNING') RETURNING analysis_pk",
      int, int, int),
    uploadId, reusedUploadId, groupId);
  return static_cast<long>(fo::stringToUnsignedLong(result.getRow(0)[0].c_str()));
}

void DatabaseHandler::markAnalysisFinished(long analysisId)
{
  dbManager.execPrepared(
    fo_dbManager_PrepareStamement(dbManager.getStruct_dbManager(), __func__,
      "UPDATE enhanced_reuse_analysis SET status='DONE', updated_at=now() WHERE analysis_pk=$1", long),
    analysisId);
}

std::vector<std::tuple<unsigned long, std::string, std::string>> DatabaseHandler::queryFilesForUpload(int uploadId)
{
  std::vector<std::tuple<unsigned long, std::string, std::string>> rows;
  auto result = dbManager.execPrepared(
    fo_dbManager_PrepareStamement(dbManager.getStruct_dbManager(), __func__,
      "SELECT DISTINCT p.pfile_pk, ut.ufile_name, p.pfile_sha1 FROM uploadtree ut "
      "INNER JOIN pfile p ON p.pfile_pk = ut.pfile_fk WHERE ut.upload_fk=$1 AND ut.pfile_fk IS NOT NULL", int),
    uploadId);
  for (int i = 0; i < result.getRowCount(); ++i)
  {
    auto row = result.getRow(i);
    rows.emplace_back(fo::stringToUnsignedLong(row[0].c_str()), row[1], row[2]);
  }
  return rows;
}

std::vector<std::string> DatabaseHandler::queryLicenseDecisions(unsigned long pfileId, int groupId)
{
  /*
   * Match Fossology\\Lib\\Dao\\PfileDao::getConclusions(): repo/global scope (scope=1),
   * decision_type IDENTIFIED (=5), latest event per license, not removed.
   * Scanner license_file rows are not used: comparison is clearing-only.
   */
  std::vector<std::string> decisions;
  auto clearingResult = dbManager.execPrepared(
    fo_dbManager_PrepareStamement(dbManager.getStruct_dbManager(), __func__,
      "WITH all_decs AS ( "
      "  SELECT cd.clearing_decision_pk, lr.rf_shortname, ce.removed, cd.decision_type "
      "  FROM clearing_decision cd "
      "  INNER JOIN clearing_decision_event cde ON cde.clearing_decision_fk = cd.clearing_decision_pk "
      "  INNER JOIN clearing_event ce ON ce.clearing_event_pk = cde.clearing_event_fk "
      "  INNER JOIN license_ref lr ON lr.rf_pk = ce.rf_fk "
      "  WHERE cd.pfile_fk=$1 AND (cd.group_fk=$2 OR cd.scope=1) "
      "  ORDER BY cd.clearing_decision_pk DESC "
      "), ranked AS ( "
      "  SELECT clearing_decision_pk, rf_shortname, removed, decision_type, "
      "    rank() OVER (PARTITION BY rf_shortname ORDER BY clearing_decision_pk DESC) AS rnk "
      "  FROM all_decs "
      ") "
      "SELECT rf_shortname FROM ranked "
      "WHERE rnk=1 AND removed=false AND decision_type=5",
      unsigned long, int),
    pfileId, groupId);

  for (int i = 0; i < clearingResult.getRowCount(); ++i)
  {
    decisions.emplace_back(clearingResult.getRow(i)[0]);
  }
  return decisions;
}

void DatabaseHandler::insertFileComparison(long analysisId, const FileComparisonResult& result, RiskLevel riskLevel)
{
  const std::string classification = (result.classification == FileClassification::IDENTICAL) ? "IDENTICAL"
    : (result.classification == FileClassification::NEW_FILE) ? "NEW"
    : (result.classification == FileClassification::DELETED) ? "DELETED" : "MODIFIED";

  const std::string modificationType = (result.modificationType == FileModificationType::COMMENT_ONLY) ? "COMMENT_ONLY"
    : (result.modificationType == FileModificationType::MINOR) ? "MINOR"
    : (result.modificationType == FileModificationType::MAJOR) ? "MAJOR"
    : (result.modificationType == FileModificationType::LICENSE_CHANGED) ? "LICENSE_CHANGED"
    : (result.modificationType == FileModificationType::CONFLICT) ? "CONFLICT" : "NONE";

  const std::string risk = (riskLevel == RiskLevel::LOW) ? "LOW"
    : (riskLevel == RiskLevel::MEDIUM) ? "MEDIUM"
    : (riskLevel == RiskLevel::HIGH) ? "HIGH" : "CRITICAL";

  dbManager.execPrepared(
    fo_dbManager_PrepareStamement(dbManager.getStruct_dbManager(), __func__,
      "INSERT INTO enhanced_reuse_file_comparison(analysis_fk, upload_pfile_fk, reused_pfile_fk, file_name, "
      "classification, modification_type, changed_lines, risk_level) "
      "VALUES($1, NULLIF($2,0), NULLIF($3,0), $4, $5, $6, $7, $8)",
      long, unsigned long, unsigned long, char*, char*, char*, int, char*),
    analysisId, result.currentPfileId, result.reusedPfileId, result.fileName.c_str(),
    classification.c_str(), modificationType.c_str(), result.changedLines, risk.c_str());
}

void DatabaseHandler::insertLicenseComparison(long analysisId, const LicenseComparisonResult& result)
{
  dbManager.execPrepared(
    fo_dbManager_PrepareStamement(dbManager.getStruct_dbManager(), __func__,
      "INSERT INTO enhanced_reuse_license_comparison(analysis_fk, upload_pfile_fk, reused_pfile_fk, "
      "current_decision, reused_decision, conflict) VALUES($1, NULLIF($2,0), NULLIF($3,0), $4, $5, $6)",
      long, unsigned long, unsigned long, char*, char*, bool),
    analysisId, result.currentPfileId, result.reusedPfileId,
    result.currentDecision.c_str(), result.reusedDecision.c_str(), result.hasConflict);
}

void DatabaseHandler::insertHistogram(long analysisId, const std::string& bucket, int count, const std::string& riskLevel)
{
  dbManager.execPrepared(
    fo_dbManager_PrepareStamement(dbManager.getStruct_dbManager(), __func__,
      "INSERT INTO enhanced_reuse_histogram(analysis_fk, histogram_key, histogram_count, risk_level) VALUES($1, $2, $3, $4)",
      long, char*, int, char*),
    analysisId, bucket.c_str(), count, riskLevel.c_str());
}
