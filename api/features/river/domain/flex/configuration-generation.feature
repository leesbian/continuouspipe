Feature:
  In order to deploy by Symfony Flex application seamlessly
  As a user
  I want CP to generate my configuration for me

  Scenario: It generates a basic configuration for Symfony
    Given the team "samuel" exists
    And the team "samuel" have the credentials of the following Docker registry:
      | full_address                                                          | attributes                                       |
      | quay.io/continuouspipe-flex/flow-00000000-0000-0000-0000-000000000000 | {"flow": "00000000-0000-0000-0000-000000000000"} |
    And I have a flow with UUID "00000000-0000-0000-0000-000000000000"
    And the flow "00000000-0000-0000-0000-000000000000" has been flex activated with the same identifier "abc123"
    And the code repository contains the fixtures folder "flex-skeleton"
    When the configuration of the tide is generated
    Then the generated configuration should contain at least:
    """
    tasks:
        00_images:
            build:
                services:
                    app:
                        image: quay.io/continuouspipe-flex/flow-00000000-0000-0000-0000-000000000000

        10_app_deployment:
            deploy:
                services:
                    app:
                        endpoints:
                            - name: app
    """

  Scenario: It adds a database when Symfony has Doctrine enabled
    Given I have a flow with UUID "00000000-0000-0000-0000-000000000000"
    And the flow "00000000-0000-0000-0000-000000000000" has been flex activated with the same identifier "abc123"
    And the code repository contains the fixtures folder "flex-skeleton"
    And the ".env.dist" file in the code repository contains:
    """
    ###> symfony/framework-bundle ###
    APP_ENV=dev
    APP_DEBUG=1
    APP_SECRET=547417d8a21a468aa18ba068702c0e9a
    ###< symfony/framework-bundle ###

    ###> doctrine/doctrine-bundle ###
    # Format described at http://docs.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/configuration.html#connecting-using-a-url
    # For a sqlite database, use: "sqlite:///%kernel.project_dir%/var/data.db"
    # Set "serverVersion" to your server version to avoid edge-case exceptions and extra database calls
    DATABASE_URL=mysql://foo:bar@postgres/baz
    ###< doctrine/doctrine-bundle ###
    """
    When the configuration of the tide is generated
    Then the generated configuration should contain at least:
    """
    tasks:
        05_database_deployment:
            deploy:
                services:
                    database:
                        specification:
                            ports:
                                - { identifier: database5432, port: 5432, protocol: TCP }

        10_app_deployment:
            deploy:
                services:
                    app:
                        specification:
                            environment_variables:
                                - { name: APP_ENV, value: dev }
                                - { name: APP_DEBUG, value: '1' }
                                - { name: APP_SECRET, value: 547417d8a21a468aa18ba068702c0e9a }
                                - { name: DATABASE_URL, value: postgres://app:app@database/app }
    """

  Scenario: It will build the image with the environment as build arguments
    Given I have a flow with UUID "00000000-0000-0000-0000-000000000000"
    And the flow "00000000-0000-0000-0000-000000000000" has been flex activated with the same identifier "abc123"
    And the code repository contains the fixtures folder "flex-skeleton"
    When the configuration of the tide is generated
    Then the generated configuration should contain at least:
    """
    tasks:
        00_images:
            build:
                services:
                    app:
                        environment:
                            - name: APP_ENV
                            - name: APP_DEBUG
                            - name: APP_SECRET
    """

  Scenario: Override the env.dist values by using variables
    Given I have a flow with UUID "00000000-0000-0000-0000-000000000000" and the following configuration:
    """
    variables:
    - name: FOO
      value: my-foo
    """
    And the flow "00000000-0000-0000-0000-000000000000" has been flex activated with the same identifier "abc123"
    And the code repository contains the fixtures folder "flex-skeleton"
    And the ".env.dist" file in the code repository contains:
    """
    ###> symfony/framework-bundle ###
    APP_ENV=dev
    APP_DEBUG=1
    APP_SECRET=547417d8a21a468aa18ba068702c0e9a
    ###< symfony/framework-bundle ###

    FOO=foo
    BAR=bar
    """
    When the configuration of the tide is generated
    Then the generated configuration should contain at least:
    """
    tasks:
        00_images:
            build:
                services:
                    app:
                        environment:
                            - name: APP_ENV
                            - name: APP_DEBUG
                            - name: APP_SECRET
                            - name: FOO
                              value: my-foo
                            - name: BAR
                              value: bar
    """

  Scenario: It displays the generated configuration on the tide's logs
    Given I have a flow with UUID "00000000-0000-0000-0000-000000000000"
    And the flow "00000000-0000-0000-0000-000000000000" has been flex activated with the same identifier "abc123"
    And the code repository contains the fixtures folder "flex-skeleton"
    When a tide is started
    Then a log containing "Generating configuration" should be created
    And this log should be successful
    And a log of type "tabs" should be created under the log "Generating configuration"
    And this log should contain a tab "Dockerfile" with a content of type "raw"
    And this log should contain a tab "docker-compose.yml" with a content of type "raw"
    And this log should contain a tab "continuous-pipe.yml" with a content of type "raw"

  Scenario: It only generates the DockerCompose and CP configuration if Dockerfile already exists
    Given I have a flow with UUID "00000000-0000-0000-0000-000000000000"
    And the flow "00000000-0000-0000-0000-000000000000" has been flex activated with the same identifier "abc123"
    And the code repository contains the fixtures folder "flex-skeleton"
    And the "Dockerfile" file in the code repository contains:
    """
    FROM php
    RUN my-build-command
    """
    When a tide is started
    Then a log containing "Generating configuration" should be created
    And this log should be successful
    And a log of type "tabs" should be created under the log "Generating configuration"
    And this log should not contain a tab "Dockerfile"
    And this log should contain a tab "docker-compose.yml" with a content of type "raw"
    And this log should contain a tab "continuous-pipe.yml" with a content of type "raw"

  Scenario: It fails to generate the configuration
    Given I have a flow with UUID "00000000-0000-0000-0000-000000000000"
    And the flow "00000000-0000-0000-0000-000000000000" has been flex activated with the same identifier "abc123"
    When a tide is started
    Then a log containing "Generating configuration" should be created
    And this log should be failed
    And a log of type "tabs" should be created under the log "Generating configuration"
    And this log should contain a tab "Dockerfile" with a content of type "text"
    And this log should contain a tab "Dockerfile" containing "File `composer.json` not found in the repository"

  Scenario: It allows to be flex and have all the configuration already
    Given I have a flow with UUID "00000000-0000-0000-0000-000000000000"
    And the flow "00000000-0000-0000-0000-000000000000" has been flex activated with the same identifier "abc123"
    And the code repository contains the fixtures folder "flex-skeleton"
    And the "Dockerfile" file in the code repository contains:
    """
    FROM php
    RUN my-build-command
    """
    And the "docker-compose.yml" file in the code repository contains:
    """
    version: '2'
    services:
        app:
            build: .
    """
    And the "continuous-pipe.yml" file in the code repository contains:
    """
    tasks:
        images:
            build:
                services:
                    app:
                        image: my-image
    """
    When a tide is started
    Then the build task should be started
    And a log containing "Generating configuration" should not be created

  Scenario: It fails when it can't generate configuration
    Given I have a flow with UUID "00000000-0000-0000-0000-000000000000"
    And the flow "00000000-0000-0000-0000-000000000000" has flex activated
    When a tide is started for the branch "master"
    And the tide should be failed

  Scenario: It uses the registry I have if any
    Given the team "samuel" exists
    And the team "samuel" have the credentials of the following Docker registry:
      | serverAddress | username |
      | docker.io     | sroze    |
    And I have a flow with UUID "00000000-0000-0000-0000-000000000000"
    And the flow "00000000-0000-0000-0000-000000000000" has been flex activated with the same identifier "abc123"
    And the code repository contains the fixtures folder "flex-skeleton"
    When the configuration of the tide is generated
    Then the generated configuration should contain at least:
    """
    tasks:
        00_images:
            build:
                services:
                    app:
                        image: docker.io/sroze/flow-00000000-0000-0000-0000-000000000000
    """

  Scenario: It fails the generation if no registry
    Given the team "samuel" exists
    And I have a flow with UUID "00000000-0000-0000-0000-000000000000"
    And the flow "00000000-0000-0000-0000-000000000000" has been flex activated with the same identifier "abc123"
    And the code repository contains the fixtures folder "flex-skeleton"
    When the configuration of the tide is generated
    Then the generated configuration should contain at least:
    """
    tasks:
        00_images:
            build:
                services:
                    app:
                        image: docker.io/could-not-guess-image-name/please-add-registry-in-team
    """
