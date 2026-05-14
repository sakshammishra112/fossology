/*
 SPDX-FileCopyrightText: © 2026 Fossology contributors

 SPDX-License-Identifier: GPL-2.0-only
*/

#include "RiskCalculator.hpp"

RiskLevel RiskCalculator::calculate(const FileComparisonResult& fileResult,
  const LicenseComparisonResult& licenseResult) const
{
  // CRITICAL: License conflicts or file conflicts
  if (licenseResult.hasConflict || fileResult.modificationType == FileModificationType::CONFLICT)
  {
    return RiskLevel::CRITICAL;
  }
  
  // HIGH: License changes or major modifications (>100 lines)
  if (fileResult.modificationType == FileModificationType::LICENSE_CHANGED || fileResult.changedLines > 100)
  {
    return RiskLevel::HIGH;
  }
  
  // MEDIUM: Moderate modifications (20-100 lines)
  if (fileResult.classification == FileClassification::MODIFIED || fileResult.changedLines > 20)
  {
    return RiskLevel::MEDIUM;
  }
  
  // LOW: Minor changes (≤20 lines) or identical files
  return RiskLevel::LOW;
}
