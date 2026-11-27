@local @local_completiondoctor
Feature: The SCORM panel explains a package and recommends settings
  In order to set up SCORM completion correctly
  As a teacher
  I need to see what the package reports and which settings to use

  Scenario: Recommendation for a package that reports completed
    Given the Completion Doctor demo course "scormstatusmismatch" exists
    When I am on the "cd-demo-scormstatusmismatch" "local_completiondoctor > Course report" page logged in as "admin"
    Then I should see "SCORM settings cannot be met"
    When I click on "SCORM analysis" "link"
    Then I should see "Articulate Storyline"
    And I should see "completed-incomplete"
    And I should see "Recommended settings (medium confidence, based on the authoring tool settings)"
    And I should see "Completed" in the "table.table-sm tr.table-warning" "css_element"
