/*
 SPDX-FileCopyrightText: © 2026 Fossology contributors

 SPDX-License-Identifier: GPL-2.0-only
*/

#ifndef SRC_ENHANCEDREUSER_AGENT_FILECOMPARATOR_HPP_
#define SRC_ENHANCEDREUSER_AGENT_FILECOMPARATOR_HPP_

#include <string>
#include <vector>

enum class FileClassification
{
  IDENTICAL,
  NEW_FILE,
  DELETED,
  MODIFIED
};

enum class FileModificationType
{
  NONE,
  COMMENT_ONLY,
  MINOR,
  MAJOR,
  LICENSE_CHANGED,
  CONFLICT
};

struct FileComparisonResult
{
  unsigned long currentPfileId;
  unsigned long reusedPfileId;
  std::string fileName;
  FileClassification classification;
  FileModificationType modificationType;
  int changedLines;
};

class FileComparator
{
public:
  std::vector<FileComparisonResult> compareChunk(
    const std::vector<std::tuple<unsigned long, std::string, std::string>>& currentFiles,
    const std::vector<std::tuple<unsigned long, std::string, std::string>>& reusedFiles) const;
};

#endif
