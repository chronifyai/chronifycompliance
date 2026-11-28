@local @local_chronifycompliance @javascript
Feature: Administrators set up a stronger password policy and multi-factor authentication step by step
  In order to fix security problems that need decisions
  As an administrator
  I need a short wizard that explains each choice, shows the exact changes before applying, and can be undone

  Background:
    # Moodle's own security checks find different things on each test machine, so they are off here.
    Given the following config values are set as admin:
      | config               | value | plugin                   |
      | enabled_coresecurity | 0     | local_chronifycompliance |

  Scenario: Set up a stronger password policy, then undo it
    Given the Chronify.Compliance demo site problem "passwordstrength" exists
    And I log in as "admin"
    When I visit "/local/chronifycompliance/security.php?group=access"
    And I click on "Start setup" "link" in the "Passwords can be as short as 6 characters" "local_chronifycompliance > Finding"
    Then I should see "Step 1 of 3"
    And I should see "Password length and characters"
    When I set the field "Minimum length" to "4"
    And I press "Next"
    Then I should see "Minimum length must be between 8 and 64."
    When I set the field "Minimum length" to "14"
    And I press "Next"
    Then I should see "Step 2 of 3"
    And I should see "Failed sign ins"
    When I press "Back"
    Then the field "Minimum length" matches value "14"
    When I press "Next"
    And I set the field "Lock an account after this many failed sign ins" to "5"
    And I press "Next"
    Then I should see "Review the changes"
    And I should see "14" in the "[data-region='guided-review']" "css_element"
    And I should see "The policy applies to new and changed passwords."
    When I press "Apply changes"
    Then "[data-region='undo-toast']" "css_element" should be visible
    When I press "Check again"
    Then I should not see "Passwords can be as short as 6 characters"
    And I should not see "Accounts are never locked after failed sign ins"

  Scenario: Undo the password policy from the toast
    Given the Chronify.Compliance demo site problem "passwordstrength" exists
    And I log in as "admin"
    When I visit "/local/chronifycompliance/security.php?group=access"
    And I click on "Start setup" "link" in the "Passwords can be as short as 6 characters" "local_chronifycompliance > Finding"
    And I press "Next"
    And I press "Next"
    And I press "Apply changes"
    And I click on "Undo fix" "button" in the "[data-region='undo-toast']" "css_element"
    And I press "Check again"
    Then I should see "Passwords can be as short as 6 characters"
    And I should see "Accounts are never locked after failed sign ins"

  Scenario: Turn on multi-factor authentication for administrators, then undo it from the toast
    Given the Chronify.Compliance demo site problem "adminmfa" exists
    And I log in as "admin"
    When I visit "/local/chronifycompliance/security.php?group=access"
    And I click on "Start setup" "link" in the "Multi-factor authentication is off" "local_chronifycompliance > Finding"
    Then I should see "How people confirm a sign in"
    When I set the field "Authenticator app" to "0"
    And I set the field "Security key or passkey" to "0"
    And I set the field "Email code" to "0"
    And I press "Next"
    And I press "Next"
    Then I should see "Choose at least one way to confirm sign ins."
    And "Apply changes" "button" should not exist
    When I click on "Back" "link"
    And I press "Back"
    And I set the field "Authenticator app" to "1"
    And I press "Next"
    Then I should see "Who and when"
    And the field "Only ask site administrators for now" matches value "1"
    When I press "Next"
    Then I should see "Review the changes"
    And I should see "Authenticator app" in the "[data-region='guided-review']" "css_element"
    And I should see "make sure you can set up one of these factors yourself"
    When I press "Apply changes"
    Then "[data-region='undo-toast']" "css_element" should be visible
    When I click on "Undo fix" "button" in the "[data-region='undo-toast']" "css_element"
    And I press "Check again"
    Then I should see "Multi-factor authentication is off"
