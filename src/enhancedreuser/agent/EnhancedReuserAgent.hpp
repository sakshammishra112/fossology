/*
 SPDX-FileCopyrightText: © 2026 Fossology contributors

 SPDX-License-Identifier: GPL-2.0-only
*/

#ifndef SRC_ENHANCEDREUSER_AGENT_ENHANCEDREUSERAGENT_HPP_
#define SRC_ENHANCEDREUSER_AGENT_ENHANCEDREUSERAGENT_HPP_

#include "DatabaseHandler.hpp"
#include "FileComparator.hpp"
#include "LicenseComparator.hpp"
#include "RiskCalculator.hpp"

class EnhancedReuserAgent
{
public:
  explicit EnhancedReuserAgent(DatabaseHandler databaseHandler);
  bool initializeAgent();
  /**
   * @param groupId         Current upload clearing group (new upload owner's group context)
   * @param reusedGroupId   Clearing group associated with reused upload (\c upload_reuse.reused_group_fk)
   */
  bool processUploadId(int uploadId, int reusedUploadId, int groupId,
    int reusedGroupId);

private:
  DatabaseHandler databaseHandler;
  FileComparator fileComparator;
  LicenseComparator licenseComparator;
  RiskCalculator riskCalculator;
};

#endif
