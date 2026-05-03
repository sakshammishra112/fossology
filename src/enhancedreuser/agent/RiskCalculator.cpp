/*
 SPDX-FileCopyrightText: © 2026 Fossology contributors

 SPDX-License-Identifier: GPL-2.0-only
*/

#include "RiskCalculator.hpp"

RiskLevel RiskCalculator::calculate(const FileComparisonResult& fileResult,
  const LicenseComparisonResult& licenseResult) const
{
  if (licenseResult.hasConflict || fileResult.modificationType == FileModificationType::CONFLICT)
  {
    return RiskLevel::CRITICAL;
  }
  if (fileResult.modificationType == FileModificationType::LICENSE_CHANGED)
  {
    return RiskLevel::HIGH;
  }
  if (fileResult.classification == FileClassification::MODIFIED)
  {
    return RiskLevel::MEDIUM;
  }
  return RiskLevel::LOW;
}
