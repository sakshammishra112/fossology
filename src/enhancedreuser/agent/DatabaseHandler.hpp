/*
 SPDX-FileCopyrightText: © 2026 Fossology contributors

 SPDX-License-Identifier: GPL-2.0-only
*/

#ifndef SRC_ENHANCEDREUSER_AGENT_DATABASEHANDLER_HPP_
#define SRC_ENHANCEDREUSER_AGENT_DATABASEHANDLER_HPP_

#include "FileComparator.hpp"
#include "LicenseComparator.hpp"
#include "RiskCalculator.hpp"
#include "libfossAgentDatabaseHandler.hpp"
#include "libfossdbmanagerclass.hpp"

#include <tuple>
#include <vector>

struct UploadReusePair
{
  int reusedUploadId{};
  int reusedGroupId{};
};

class DatabaseHandler : public fo::AgentDatabaseHandler
{
public:
  explicit DatabaseHandler(fo::DbManager dbManager);
  DatabaseHandler(DatabaseHandler&& other) : fo::AgentDatabaseHandler(std::move(other)) {};
  DatabaseHandler spawn() const;

  bool queryLatestReuseTarget(int uploadId, int groupId, UploadReusePair& out);

  long createAnalysis(int uploadId, int reusedUploadId, int groupId);
  void markAnalysisFinished(long analysisId);
  std::vector<std::tuple<unsigned long, std::string, std::string>> queryFilesForUpload(int uploadId);
  std::vector<std::string> queryLicenseDecisions(unsigned long pfileId, int groupId);
  void insertFileComparison(long analysisId, const FileComparisonResult& result, RiskLevel riskLevel);
  void insertLicenseComparison(long analysisId, const LicenseComparisonResult& result);
  void insertHistogram(long analysisId, const std::string& bucket, int count, const std::string& riskLevel);
};

#endif
