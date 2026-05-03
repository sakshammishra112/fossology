/*
 SPDX-FileCopyrightText: © 2026 Fossology contributors

 SPDX-License-Identifier: GPL-2.0-only
*/

#include "DatabaseHandler.hpp"
#include "EnhancedReuserAgent.hpp"
#include "libfossologyCPP.hpp"

#include <cstdlib>
#include <iostream>

extern "C"
{
#include "libfossology.h"
}

int main(int argc, char** argv)
{
  fo::DbManager dbManager(&argc, argv);
  DatabaseHandler databaseHandler(dbManager);
  EnhancedReuserAgent agent(databaseHandler.spawn());

  if (!agent.initializeAgent())
  {
    std::cerr << "enhancedreuser: initializeAgent failed\n";
    fo_scheduler_disconnect(1);
    return 1;
  }

  while (fo_scheduler_next() != nullptr)
  {
    char* current = fo_scheduler_current();
    int uploadId = current != nullptr ? atoi(current) : 0;
    int groupId = fo_scheduler_groupID();

    if (uploadId <= 0)
    {
      continue;
    }

    UploadReusePair reuse;
    if (!databaseHandler.queryLatestReuseTarget(uploadId, groupId, reuse))
    {
      std::cerr << "enhancedreuser: no upload_reuse row for upload " << uploadId
                << ", group " << groupId << "; skipping\n";
      fo_scheduler_heart(0);
      continue;
    }

    if (!agent.processUploadId(uploadId, reuse.reusedUploadId, groupId, reuse.reusedGroupId))
    {
      fo_scheduler_disconnect(2);
      return 2;
    }
    fo_scheduler_heart(0);
  }

  fo_scheduler_disconnect(0);
  return 0;
}
