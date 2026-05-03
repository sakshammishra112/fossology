/*
 SPDX-FileCopyrightText: © 2026 Fossology contributors

 SPDX-License-Identifier: GPL-2.0-only
*/

#include "LicenseComparator.hpp"

#include <algorithm>
#include <sstream>

namespace
{
  std::string joinSorted(const std::vector<std::string>& names)
  {
    if (names.empty())
    {
      return "";
    }
    std::vector<std::string> sorted = names;
    std::sort(sorted.begin(), sorted.end());
    std::ostringstream oss;
    for (size_t i = 0; i < sorted.size(); ++i)
    {
      if (i > 0)
      {
        oss << ", ";
      }
      oss << sorted[i];
    }
    return oss.str();
  }
} // namespace

LicenseComparisonResult LicenseComparator::compare(unsigned long currentPfileId, unsigned long reusedPfileId,
  const std::vector<std::string>& currentDecisions, const std::vector<std::string>& reusedDecisions) const
{
  const std::string currentDecision = joinSorted(currentDecisions);
  const std::string reusedDecision = joinSorted(reusedDecisions);
  const bool hasConflict = (currentDecision != reusedDecision);

  return {currentPfileId, reusedPfileId, currentDecision, reusedDecision, hasConflict};
}
