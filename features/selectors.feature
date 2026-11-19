Feature: Finding widgets with CSS selectors
  Widgets are elements named after their class (InputWidget is "input",
  SelectListWidget is "select-list"), with their id, style classes, label
  and value as attributes, :focus and :visible, and the text they show.
  In process the driver reads the widgets; as a process, the demo's entry
  point installs TuiProbe, which shares them.

  Background:
    Given I start the app

  Scenario: Text inside an element
    When I send "Ada<Enter>"
    Then I should see "Hello, Ada!" in "#greeting"
    And I should see "Hello" in "#form > .message"
    And I should not see "Hello" in "#choice"
    And I should see "Name: Ada" in "input[value='Ada']"

  Scenario: Counting and finding
    Then there should be 2 elements matching ".message"
    And there should be 1 element matching "#form select-list"
    And there should be 0 elements matching "#form > text.title"
    And I should see an element matching "text.title:contains('Tuink')"
    And I should not see an element matching "select-list:focus"

  Scenario: Elements that are not drawn
    Then there should be 1 element matching "#greeting"
    And I should not see an element matching "#greeting"
    When I send "Ada<Enter>"
    Then I should see an element matching "#greeting:visible"

  Scenario: What an element shows
    Then the element "select-list" should show:
      """
      → Red
        Green
        Blue
      """

  Scenario: Focus by selector
    Then the element "input:focus" should be focused
    When I focus "#form select-list"
    Then the element "#color" should be focused
    When I focus the element "#form > input"
    Then the element "#name" should have the value ""
