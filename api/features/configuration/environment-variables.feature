Feature:
  In order to configure my tide
  As a user
  I want to be able to use and pass configuration variables

  Scenario: The variables are replaced in the configuration
    Given I have a flow with the following configuration:
    """
    environment_variables:
        - name: FOO
          value: BAR
    """
    And I have a "continuous-pipe.yml" file in my repository that contains:
    """
    tasks:
        named:
            deploy:
                cluster: ${FOO}
                services: []
    """
    When a tide is created
    Then the configuration of the tide should contain at least:
    """
    tasks:
        named:
            deploy:
                cluster: BAR
    """

  Scenario: Variables with condition
    Given there is 1 application images in the repository
    And I have a "continuous-pipe.yml" file in my repository that contains:
    """
    environment_variables:
        - name: CLUSTER
          value: bar
          condition: 'code_reference.branch == "production"'
        - name: CLUSTER
          value: foo
          condition: 'code_reference.branch == "master"'
        - name: CLUSTER
          value: baz
          condition: 'code_reference.branch == "feature/ABC"'

    tasks:
        deployment:
            deploy:
                cluster: ${CLUSTER}
    """
    When the configuration of the tide is generated for the branch "master"
    Then the generated configuration should contain at least:
    """
    tasks:
        deployment:
            deploy:
                cluster: foo
    """

  Scenario: Variable from an expression
    Given I have a "continuous-pipe.yml" file in my repository that contains:
    """
    environment_variables:
        - name: BRANCH_NAME
          expression: code_reference.branch

    tasks:
        named:
            deploy:
                cluster: ${BRANCH_NAME}
                services: []
    """
    When the configuration of the tide is generated for the branch "master"
    Then the generated configuration should contain at least:
    """
    tasks:
        named:
            deploy:
                cluster: master
    """
