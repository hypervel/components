export const sidebarConfig = ({
    '/docs': [
        {
            text: 'Documentation',
            prefix: '/docs/',
            children: [
                {
                    text: 'Prologue',
                    collapsible: true,
                    children: [
                        {
                            text: 'Contributing Guide',
                            link: 'contributions',
                        }
                    ]
                },
                {
                    text: 'Getting Started',
                    collapsible: true,
                    children: [
                        {
                            text: 'Introduction',
                            link: 'introduction',
                        },
                        {
                            text: 'Installation',
                            link: 'installation',
                        },
                        {
                            text: 'Configuration',
                            link: 'configuration',
                        },
                        {
                            text: 'Directory Structure',
                            link: 'structure',
                        },
                        {
                            text: 'Deployment',
                            link: 'deployment',
                        }
                    ],
                },
                {
                    text: 'Architecture Concepts',
                    collapsible: true,
                    children: [
                        {
                            text: 'Request Lifecycle',
                            link: 'lifecycle',
                        },
                        {
                            text: 'Service Container',
                            link: 'container',
                        },
                        {
                            text: 'Service Providers',
                            link: 'providers',
                        },
                        {
                            text: 'Facades',
                            link: 'facades',
                        }
                    ],
                },
                {
                    text: 'The Basics',
                    collapsible: true,
                    children: [
                        {
                            text: 'Routing',
                            link: 'routing',
                        },
                        {
                            text: 'Middleware',
                            link: 'middleware',
                        },
                        {
                            text: 'CSRF Protection',
                            link: 'csrf',
                        },
                        {
                            text: 'Controllers',
                            link: 'controllers',
                        },
                        {
                            text: 'Requests',
                            link: 'requests',
                        },
                        {
                            text: 'Responses',
                            link: 'responses',
                        },
                        {
                            text: 'Views',
                            link: 'views',
                        },
                        {
                            text: 'Blade Templates',
                            link: 'blade',
                        },
                        {
                            text: 'URL Generation',
                            link: 'urls',
                        },
                        {
                            text: 'Session',
                            link: 'session',
                        },
                        {
                            text: 'Validation',
                            link: 'validation',
                        },
                        {
                            text: 'Error Handling',
                            link: 'errors',
                        },
                        {
                            text: 'Logging',
                            link: 'logging',
                        }
                    ],
                },
                {
                    text: 'Digging Deeper',
                    collapsible: true,
                    children: [
                        {
                            text: 'Artisan Console',
                            link: 'artisan',
                        },
                        {
                            text: 'Broadcasting',
                            link: 'broadcasting',
                        },
                        {
                            text: 'Cache',
                            link: 'cache',
                        },
                        {
                            text: 'Collections',
                            link: 'collections',
                        },
                        {
                            text: 'Context',
                            link: 'context',
                        },
                        {
                            text: 'Coroutine',
                            link: 'coroutine',
                        },
                        {
                            text: 'Contracts',
                            link: 'contracts',
                        },
                        {
                            text: 'Data Objects',
                            link: 'data-objects',
                        },
                        {
                            text: 'Events',
                            link: 'events',
                        },
                        {
                            text: 'File Storage',
                            link: 'filesystem',
                        },
                        {
                            text: 'Helpers',
                            link: 'helpers',
                        },
                        {
                            text: 'HTTP Client',
                            link: 'http-client',
                        },
                        {
                            text: 'Localization',
                            link: 'localization',
                        },
                        {
                            text: 'Mail',
                            link: 'mail',
                        },
                        {
                            text: 'Notifications',
                            link: 'notifications',
                        },
                        {
                            text: 'Package Development',
                            link: 'packages',
                        },
                        {
                            text: 'Package Porting',
                            link: 'packages-porting',
                        },
                        {
                            text: 'Processes',
                            link: 'processes',
                        },
                        {
                            text: 'Queues',
                            link: 'queues',
                        },
                        {
                            text: 'Rate Limiting',
                            link: 'rate-limiting',
                        },
                        {
                            text: 'Strings',
                            link: 'strings',
                        },
                        {
                            text: 'Task Scheduling',
                            link: 'scheduling',
                        },
                    ],
                },
                {
                    text: 'Security',
                    collapsible: true,
                    children: [
                        {
                            text: 'Authentication',
                            link: 'authentication',
                        },
                        {
                            text: 'Authorization',
                            link: 'authorization',
                        },
                        {
                            text: 'Encryption',
                            link: 'encryption',
                        },
                        {
                            text: 'Hashing',
                            link: 'hashing',
                        }
                    ],
                },
                {
                    text: 'Database',
                    collapsible: true,
                    children: [
                        {
                            text: 'Getting Started',
                            link: 'database',
                        },
                        {
                            text: 'Query Builder',
                            link: 'queries',
                        },
                        {
                            text: 'Pagination',
                            link: 'pagination',
                        },
                        {
                            text: 'Migrations',
                            link: 'migrations',
                        },
                        {
                            text: 'Seeding',
                            link: 'seeding',
                        },
                        {
                            text: 'Redis',
                            link: 'redis',
                        }
                    ],
                },
                {
                    text: 'Eloquent ORM',
                    collapsible: true,
                    children: [
                        {
                            text: 'Getting Started',
                            link: 'eloquent',
                        },
                        {
                            text: 'Relationships',
                            link: 'eloquent-relationships',
                        },
                        {
                            text: 'Collections',
                            link: 'eloquent-collections',
                        },
                        {
                            text: 'Mutators / Casts',
                            link: 'eloquent-mutators',
                        },
                        {
                            text: 'API Resources',
                            link: 'eloquent-resources',
                        },
                        {
                            text: 'Serialization',
                            link: 'eloquent-serialization',
                        },
                        {
                            text: 'Factories',
                            link: 'eloquent-factories',
                        }
                    ],
                },
                {
                    text: 'Testing',
                    collapsible: true,
                    children: [
                        {
                            text: 'Getting Started',
                            link: 'testing',
                        },
                        {
                            text: 'HTTP Tests',
                            link: 'http-tests',
                        },
                        {
                            text: 'Console Tests',
                            link: 'console-tests',
                        },
                        {
                            text: 'Database',
                            link: 'database-testing',
                        },
                        {
                            text: 'Mocking',
                            link: 'mocking',
                        },
                        {
                            text: 'Packages Toolkit',
                            link: 'testbench',
                        },
                    ],
                },
                {
                    text: 'Packages',
                    collapsible: true,
                    children: [
                        {
                            text: 'Horizon',
                            link: 'horizon',
                        },
                        {
                            text: 'Sanctum',
                            link: 'sanctum',
                        },
                        {
                            text: 'Socialite',
                            link: 'socialite',
                        },
                        {
                            text: 'Permission',
                            link: 'permission',
                        },
                        {
                            text: 'Prompts',
                            link: 'prompts',
                        },
                        {
                            text: 'Nested Set',
                            link: 'nested-set',
                        },
                        {
                            text: 'Telescope',
                            link: 'telescope',
                        }
                    ],
                },
            ],
        }
    ],
})