'use strict';

angular.module('continuousPipeRiver')
    .service('$remoteResource', function($http) {
        var resources = {};

        this.get = function(name) {
            if (!(name in resources)) {
                resources[name] = {
                    status: 'unknown'
                };
            }

            return resources[name];
        };

        this.load = function(name, promise) {
            var resource = this.get(name),
                resourceController = this;

            resource.status = 'loading';

            promise.then(function(result) {
                resource.status = 'loaded';

                // Handle the paginated lists
                if (result && result.pagination) {
                    resource.more = result.pagination.hasMore;
                    resource.loadMore = function() {
                        return resourceController.load(name, result.pagination.loadMore());
                    };
                }

                return result;
            }, function(error) {
                if (error.status == -1) {
                    // Request has been cancelled; ignore.
                    return;
                }

                resource.status = 'error';
                resource.error = $http.getError(error) || 'An error occurred while loading '+name;
            });

            return promise;
        };

        this.remove = function(name) {
            delete resources[name];
        };
    });
