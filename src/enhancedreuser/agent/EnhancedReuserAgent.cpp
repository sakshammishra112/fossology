/*
 SPDX-FileCopyrightText: © 2026 Fossology contributors

 SPDX-License-Identifier: GPL-2.0-only
*/

#include "EnhancedReuserAgent.hpp"

#include <algorithm>
#include <map>

extern "C"
{
#include "libfossology.h"
}

EnhancedReuserAgent::EnhancedReuserAgent(DatabaseHandler databaseHandler) :
  databaseHandler(std::move(databaseHandler))
{
}

bool EnhancedReuserAgent::initializeAgent()
{
  return true;
}

bool EnhancedReuserAgent::processUploadId(int uploadId, int reusedUploadId, int groupId,
  int reusedGroupId)
{
  long analysisId = databaseHandler.createAnalysis(uploadId, reusedUploadId, groupId);

  auto currentFiles = databaseHandler.queryFilesForUpload(uploadId);
  auto reusedFiles = databaseHandler.queryFilesForUpload(reusedUploadId);

  std::map<std::string, int> histogram;

  /*
   * Single comparison over both trees so reused-only "deleted" rows are not duplicated.
   */
  auto fileResults = fileComparator.compareChunk(currentFiles, reusedFiles);

  const size_t n = fileResults.size();
  const size_t heartbeatEvery =
    n == 0 ? 1 : std::max<size_t>(1, n / static_cast<size_t>(80));

  for (size_t i = 0; i < n; ++i)
  {
    const auto& fileResult = fileResults[i];
    LicenseComparisonResult licenseResult;

    if (fileResult.currentPfileId > 0 && fileResult.reusedPfileId > 0)
    {
      auto currentDecisions = databaseHandler.queryLicenseDecisions(
        fileResult.currentPfileId, groupId);
      auto reusedDecisions = databaseHandler.queryLicenseDecisions(
        fileResult.reusedPfileId, reusedGroupId);
      licenseResult = licenseComparator.compare(fileResult.currentPfileId,
        fileResult.reusedPfileId, currentDecisions, reusedDecisions);
      databaseHandler.insertLicenseComparison(analysisId, licenseResult);
    }

    RiskLevel riskLevel = riskCalculator.calculate(fileResult, licenseResult);
    databaseHandler.insertFileComparison(analysisId, fileResult, riskLevel);

    if (fileResult.classification == FileClassification::IDENTICAL)
    {
      histogram["IDENTICAL"]++;
    }
    else if (fileResult.classification == FileClassification::NEW_FILE)
    {
      histogram["NEW"]++;
    }
    else if (fileResult.classification == FileClassification::DELETED)
    {
      histogram["DELETED"]++;
    }
    else
    {
      histogram["MODIFIED"]++;
    }

    if ((i + 1) % heartbeatEvery == 0)
    {
      fo_scheduler_heart(1);
    }
  }

  fo_scheduler_heart(0);

  for (const auto& kv : histogram)
  {
    databaseHandler.insertHistogram(analysisId, kv.first, kv.second, "MIXED");
  }
  databaseHandler.markAnalysisFinished(analysisId);
  return true;
}
