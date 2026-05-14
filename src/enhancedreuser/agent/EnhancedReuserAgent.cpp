/*
 SPDX-FileCopyrightText: 2026 Fossology contributors

 SPDX-License-Identifier: GPL-2.0-only
*/

#include "EnhancedReuserAgent.hpp"
#include "DatabaseHandler.hpp"
#include "LicenseComparator.hpp"
#include <algorithm>
#include <map>
#include <sstream>

extern "C"
{
#include "libfossology.h"
}

std::string joinSorted(const std::vector<std::string>& decisions)
{
  std::stringstream ss;
  std::vector<std::string> sortedDecisions = decisions;
  std::sort(sortedDecisions.begin(), sortedDecisions.end());
  for (const auto& decision : sortedDecisions)
  {
    ss << decision << ",";
  }
  std::string result = ss.str();
  if (!result.empty())
  {
    result.pop_back(); // Remove trailing comma
  }
  return result;
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
  std::map<std::string, int> licenseHistogram;

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

    // Generate license comparison for all file pairs (not just those with license decisions)
    if (fileResult.currentPfileId > 0 && fileResult.reusedPfileId > 0)
    {
      auto currentDecisions = databaseHandler.queryLicenseDecisions(
        fileResult.currentPfileId, groupId);
      auto reusedDecisions = databaseHandler.queryLicenseDecisions(
        fileResult.reusedPfileId, reusedGroupId);
      licenseResult = licenseComparator.compare(fileResult.currentPfileId,
        fileResult.reusedPfileId, currentDecisions, reusedDecisions);
      databaseHandler.insertLicenseComparison(analysisId, licenseResult);
      
      // Collect license statistics
      if (licenseResult.hasConflict)
      {
        // Count licenses that changed
        for (const auto& currentLicense : currentDecisions)
        {
          if (std::find(reusedDecisions.begin(), reusedDecisions.end(), currentLicense) == reusedDecisions.end())
          {
            licenseHistogram["LICENSE_MODIFIED"]++;
          }
        }
        for (const auto& reusedLicense : reusedDecisions)
        {
          if (std::find(currentDecisions.begin(), currentDecisions.end(), reusedLicense) == currentDecisions.end())
          {
            licenseHistogram["LICENSE_REMOVED"]++;
          }
        }
      }
    }
    else
    {
      // Handle files where one side doesn't exist (NEW or REMOVED files)
      std::string currentDecision = "";
      std::string reusedDecision = "";
      
      if (fileResult.currentPfileId > 0)
      {
        auto currentDecisions = databaseHandler.queryLicenseDecisions(
          fileResult.currentPfileId, groupId);
        currentDecision = joinSorted(currentDecisions);
      }
      
      if (fileResult.reusedPfileId > 0)
      {
        auto reusedDecisions = databaseHandler.queryLicenseDecisions(
          fileResult.reusedPfileId, reusedGroupId);
        reusedDecision = joinSorted(reusedDecisions);
      }
      
      // Create license comparison entry for NEW/REMOVED files
      LicenseComparisonResult fallbackResult = {
        fileResult.currentPfileId > 0 ? fileResult.currentPfileId : 0,
        fileResult.reusedPfileId > 0 ? fileResult.reusedPfileId : 0,
        currentDecision,
        reusedDecision,
        false  // No conflict for NEW/REMOVED files
      };
      databaseHandler.insertLicenseComparison(analysisId, fallbackResult);
    }
    
    if (fileResult.classification == FileClassification::NEW_FILE)
    {
      // Count licenses in new files
      auto currentDecisions = databaseHandler.queryLicenseDecisions(
        fileResult.currentPfileId, groupId);
      for (const auto& currentLicense : currentDecisions)
      {
        licenseHistogram["LICENSE_ADDED"]++;
      }
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
  
  // Insert license statistics
  for (const auto& kv : licenseHistogram)
  {
    databaseHandler.insertHistogram(analysisId, kv.first, kv.second, "LICENSE");
  }
  databaseHandler.markAnalysisFinished(analysisId);
  return true;
}
