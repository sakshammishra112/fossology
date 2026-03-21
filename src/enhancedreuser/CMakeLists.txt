#[=======================================================================[
SPDX-License-Identifier: GPL-2.0-only
SPDX-FileCopyrightText: © Fossology contributors
#]=======================================================================]

cmake_minimum_required(VERSION 3.18 FATAL_ERROR)
project(enhancedreuser)

include(${CMAKE_CURRENT_SOURCE_DIR}/../../cmake/SetDefaults.cmake)

add_subdirectory(${CMAKE_CURRENT_SOURCE_DIR}/agent)

generate_version()

# Install UI templates and PHP plugin files
install(DIRECTORY ui
    DESTINATION ${FO_MODDIR}/${PROJECT_NAME}
    COMPONENT enhancedreuser
    FILES_MATCHING
    PATTERN *.php
    PATTERN *.twig)

# Install REST API controller
install(DIRECTORY api
    DESTINATION ${FO_MODDIR}/${PROJECT_NAME}
    COMPONENT enhancedreuser
    FILES_MATCHING
    PATTERN *.php)

install(FILES enhancedreuser.conf "${CMAKE_CURRENT_BINARY_DIR}/VERSION"
    DESTINATION ${FO_MODDIR}/${PROJECT_NAME}
    COMPONENT enhancedreuser)

add_symlink()
