/*
 SPDX-FileCopyrightText: © 2026 Fossology contributors

 SPDX-License-Identifier: GPL-2.0-only
*/

#include "FileComparator.hpp"

#include <map>
#include <set>

std::vector<FileComparisonResult> FileComparator::compareChunk(
  const std::vector<std::tuple<unsigned long, std::string, std::string>>& currentFiles,
  const std::vector<std::tuple<unsigned long, std::string, std::string>>& reusedFiles) const
{
  std::vector<FileComparisonResult> results;
  std::map<std::string, std::tuple<unsigned long, std::string>> reusedByChecksum;
  std::map<std::string, std::tuple<unsigned long, std::string>> reusedByName;
  std::set<unsigned long> matchedReusedIds;

  for (const auto& file : reusedFiles)
  {
    reusedByChecksum[std::get<2>(file)] = std::make_tuple(std::get<0>(file), std::get<1>(file));
    reusedByName[std::get<1>(file)] = std::make_tuple(std::get<0>(file), std::get<2>(file));
  }

  for (const auto& current : currentFiles)
  {
    const unsigned long currentId = std::get<0>(current);
    const std::string& currentName = std::get<1>(current);
    const std::string& currentChecksum = std::get<2>(current);

    auto byChecksum = reusedByChecksum.find(currentChecksum);
    if (byChecksum != reusedByChecksum.end())
    {
      matchedReusedIds.insert(std::get<0>(byChecksum->second));
      results.push_back({currentId, std::get<0>(byChecksum->second), currentName,
        FileClassification::IDENTICAL, FileModificationType::NONE, 0});
      continue;
    }

    auto byName = reusedByName.find(currentName);
    if (byName != reusedByName.end())
    {
      matchedReusedIds.insert(std::get<0>(byName->second));
      results.push_back({currentId, std::get<0>(byName->second), currentName,
        FileClassification::MODIFIED, FileModificationType::MINOR, 1});
      continue;
    }

    results.push_back({currentId, 0, currentName, FileClassification::NEW_FILE,
      FileModificationType::NONE, 0});
  }

  for (const auto& reused : reusedFiles)
  {
    const unsigned long reusedId = std::get<0>(reused);
    if (matchedReusedIds.find(reusedId) == matchedReusedIds.end())
    {
      results.push_back({0, reusedId, std::get<1>(reused), FileClassification::DELETED,
        FileModificationType::NONE, 0});
    }
  }

  return results;
}
