/*
 SPDX-FileCopyrightText: © 2026 Fossology contributors

 SPDX-License-Identifier: GPL-2.0-only
*/

#ifndef SRC_ENHANCEDREUSER_AGENT_LICENSECOMPARATOR_HPP_
#define SRC_ENHANCEDREUSER_AGENT_LICENSECOMPARATOR_HPP_

#include <string>
#include <vector>

struct LicenseComparisonResult
{
  unsigned long currentPfileId;
  unsigned long reusedPfileId;
  std::string currentDecision;
  std::string reusedDecision;
  bool hasConflict;
};

class LicenseComparator
{
public:
  LicenseComparisonResult compare(unsigned long currentPfileId, unsigned long reusedPfileId,
    const std::vector<std::string>& currentDecisions, const std::vector<std::string>& reusedDecisions) const;
};

#endif
