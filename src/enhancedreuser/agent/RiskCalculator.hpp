/*
 SPDX-FileCopyrightText: © 2026 Fossology contributors

 SPDX-License-Identifier: GPL-2.0-only
*/

#ifndef SRC_ENHANCEDREUSER_AGENT_RISKCALCULATOR_HPP_
#define SRC_ENHANCEDREUSER_AGENT_RISKCALCULATOR_HPP_

#include "FileComparator.hpp"
#include "LicenseComparator.hpp"

enum class RiskLevel
{
  LOW,
  MEDIUM,
  HIGH,
  CRITICAL
};

class RiskCalculator
{
public:
  RiskLevel calculate(const FileComparisonResult& fileResult,
    const LicenseComparisonResult& licenseResult) const;
};

#endif
