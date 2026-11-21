Feature: What the application starts with, and the files it works on

  Each scenario has a workspace of its own: files written there are the
  application's to read and change, and "%workspace%" names it.

  Scenario: An argument naming a file in the workspace
    Given a file "names/first.txt" containing "Ada"
    And I start the app with the arguments "%workspace%/names/first.txt"
    Then the "#name" field should contain "Ada"

  Scenario: A file the application saves
    Given I start the app with the argument "%workspace%/saved.txt"
    And the file "saved.txt" should not exist
    When I type "Grace"
    And I press "Ctrl+S"
    Then I should see "saved to saved.txt"
    And the file "saved.txt" should exist
    And the file "saved.txt" should contain "Grace"
    And the file "saved.txt" should not contain "Ada"
    And the file "saved.txt" should contain exactly:
      """
      Grace
      """

  Scenario: Files outlive the application, and restarts read them again
    Given an empty file "notes/empty.txt"
    And a directory "cache"
    And a file "name.txt" containing:
      """
      Linus
      """
    And I start the app with the arguments "'%workspace%/name.txt' --unused"
    When I type " T"
    And I press "Ctrl+S"
    And I stop the app
    Then the file "name.txt" should contain exactly:
      """
      Linus T
      """
    When I restart the app
    Then the "#name" field should contain "Linus T"

  Scenario: Empty arguments remove those of an earlier start
    Given a file "name.txt" containing "Ada"
    And I start the app with the arguments "%workspace%/name.txt"
    And the "#name" field should contain "Ada"
    When I stop the app
    And I start the app with the arguments ""
    Then the "#name" field should not contain "Ada"

  Scenario: Removing a file while the application runs
    Given a file "name.txt" containing "Ada"
    And a directory "cache-dir/nested"
    And I start the app with the arguments "%workspace%/name.txt"
    When I delete "name.txt"
    Then the file "name.txt" should not exist
    When I remove "cache-dir"
    Then the file "cache-dir" should not exist

  Scenario: An environment variable
    Given the environment variable "DEMO_GREETING" is "Bonjour"
    And I start the app
    When I send "Ada<Enter>"
    Then I should see "Bonjour, Ada!"

  Scenario: The workspace in a variable
    Given the environment variable "DEMO_GREETING" is "Hi from %workspace%"
    And I start the app in a 200x12 terminal
    When I send "Ada<Enter>"
    Then I should see text matching "/Hi from \/.+\/tuink-workspace-[0-9a-f]+, Ada!/"

  Scenario: A terminal that speaks the kitty keyboard protocol
    Given the terminal supports the kitty keyboard protocol
    And I start the app
    Then I should see "kitty keys"

  Scenario: An executable file
    Given an executable file "bin/tool" containing:
      """
      #!/bin/sh
      echo hi
      """
    Then the file "bin/tool" should contain "echo hi"
