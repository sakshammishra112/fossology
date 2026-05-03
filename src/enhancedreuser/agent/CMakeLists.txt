#[=======================================================================[
SPDX-License-Identifier: GPL-2.0-only
SPDX-FileCopyrightText: © 2026 Fossology contributors
#]=======================================================================]

set(FO_CWD ${CMAKE_CURRENT_SOURCE_DIR})
set(CMAKE_CXX_FLAGS "${CMAKE_CXX_FLAGS} ${FO_CXX_FLAGS}")

include_directories(
    ${glib_INCLUDE_DIRS}
    ${PostgreSQL_INCLUDE_DIRS}
    ${FO_CLIB_SRC}
    ${FO_CXXLIB_SRC})

if(NOT TARGET fossologyCPP)
    add_subdirectory(${FO_CXXLIB_SRC} buildcxx EXCLUDE_FROM_ALL)
endif()

add_executable(enhancedreuser "")
target_compile_definitions(enhancedreuser
    PRIVATE
    _FILE_OFFSET_BITS=64
    DATADIR="${FO_MODDIR}"
    VERSION_S="${FO_VERSION}"
    COMMIT_HASH_S="${FO_COMMIT_HASH}")

target_sources(enhancedreuser
    PRIVATE
    ${FO_CWD}/EnhancedReuserAgent.cpp
    ${FO_CWD}/FileComparator.cpp
    ${FO_CWD}/LicenseComparator.cpp
    ${FO_CWD}/RiskCalculator.cpp
    ${FO_CWD}/DatabaseHandler.cpp
    ${FO_CWD}/enhancedreuser.cpp)

target_link_libraries(enhancedreuser
    PRIVATE fossologyCPP ${Boost_LIBRARIES})

install(TARGETS enhancedreuser
    RUNTIME
    DESTINATION ${FO_MODDIR}/${PROJECT_NAME}/agent
    COMPONENT enhancedreuser)
