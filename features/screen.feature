Feature: Checking the screen

  Scenario: The whole screen
    Given I start the app in a 60x12 terminal
    And I should see "Status: ready"
    When I type "Ada"
    Then the screen should be:
      """
      Tuink demo
      Status: ready
      Size: 60x12
      Name: Ada
      → Red
        Green
        Blue
      Enter: submit  Esc: leave  Ctrl+C: quit
      """

  Scenario: Work that finishes later
    Given I start the app
    Then I should see "Status: loading"
    And I should eventually see "Status: ready"
    And the status line should say "ready"
    And I should not see "Status: loading"

  Scenario: The plan's wording of the whole-screen check
    Given I start the app in a 60x10 terminal
    And I should see "Status: ready"
    Then the screen should match:
      """
      Tuink demo
      Status: ready
      Size: 60x10
      Name:
      → Red
        Green
        Blue
      Enter: submit  Esc: leave  Ctrl+C: quit
      """

  Scenario: A region of the screen
    Given I start the app
    When I press "Enter"
    And I press "Down"
    Then the region at line 6, column 1 should be:
      """
        Red
      → Green
      """
    And the region at line 1, column 7 should be:
      """
      demo
      """

  Scenario: Matching text
    Given I start the app
    Then I should see text matching "/Size: \d+x\d+/"

  Scenario: Waiting
    Given I start the app
    When I wait 0.4 seconds
    Then line 2 should contain "ready"

  Scenario: A snapshot
    Given I start the app in a 50x10 terminal
    And I should see "Status: ready"
    When I send "Ada<Enter>"
    And I press "Down"
    Then the screen should match the snapshot "after-greeting"
    And the screen should match snapshot "after-greeting"

  Scenario: Printing the screen while writing a scenario
    Given I start the app
    Then print the screen
