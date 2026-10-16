# MCP port remaining files

Use with [the design plan](2026-10-16-mcp-port.md). Paths on the left are relative to the upstream MCP checkout. Copy with cp, then read and adapt each file; preserve applicable upstream tests. Work alphabetically within each implementation slice. Remove completed entries; keep no command logs, review history or completed-work ledger here.

## Configuration, routes, stubs and assets

- config/mcp.php → src/mcp/config/mcp.php
- resources/js/mcp-sdk.js → src/mcp/resources/js/mcp-sdk.js
- resources/js/mcp-sdk.min.js → src/mcp/resources/js/mcp-sdk.min.js
- resources/views/mcp/authorize.blade.php → src/mcp/resources/views/mcp/authorize.blade.php
- resources/views/mcp/components/app.blade.php → src/mcp/resources/views/mcp/components/app.blade.php
- routes/ai.php → src/mcp/routes/ai.php
- stubs/mcp-app-resource.stub → src/mcp/stubs/mcp-app-resource.stub
- stubs/mcp-app-resource.view.stub → src/mcp/stubs/mcp-app-resource.view.stub
- stubs/mcp-prompt.stub → src/mcp/stubs/mcp-prompt.stub
- stubs/mcp-resource.stub → src/mcp/stubs/mcp-resource.stub
- stubs/mcp-server.stub → src/mcp/stubs/mcp-server.stub
- stubs/mcp-tool.stub → src/mcp/stubs/mcp-tool.stub

## Source

- src/Client.php → src/mcp/src/Client.php
- src/Client/ClientManager.php → src/mcp/src/Client/ClientManager.php
- src/Client/Contracts/Method.php → src/mcp/src/Client/Contracts/Method.php
- src/Client/Contracts/MirrorsParameters.php → src/mcp/src/Client/Contracts/MirrorsParameters.php
- src/Client/Contracts/Transport.php → src/mcp/src/Client/Contracts/Transport.php
- src/Client/Contracts/UsesProtocol.php → src/mcp/src/Client/Contracts/UsesProtocol.php
- src/Client/Exceptions/AuthorizationRequiredException.php → src/mcp/src/Client/Exceptions/AuthorizationRequiredException.php
- src/Client/Exceptions/OAuthException.php → src/mcp/src/Client/Exceptions/OAuthException.php
- src/Client/Exceptions/TimeoutException.php → src/mcp/src/Client/Exceptions/TimeoutException.php
- src/Client/Exceptions/TransportException.php → src/mcp/src/Client/Exceptions/TransportException.php
- src/Client/Methods/Concerns/PaginatesList.php → src/mcp/src/Client/Methods/Concerns/PaginatesList.php
- src/Client/Methods/Discover.php → src/mcp/src/Client/Methods/Discover.php
- src/Client/Methods/Initialize.php → src/mcp/src/Client/Methods/Initialize.php
- src/Client/Methods/Ping.php → src/mcp/src/Client/Methods/Ping.php
- src/Client/Methods/Prompts/GetPrompt.php → src/mcp/src/Client/Methods/Prompts/GetPrompt.php
- src/Client/Methods/Prompts/ListPrompts.php → src/mcp/src/Client/Methods/Prompts/ListPrompts.php
- src/Client/Methods/Resources/ListResources.php → src/mcp/src/Client/Methods/Resources/ListResources.php
- src/Client/Methods/Resources/ReadResource.php → src/mcp/src/Client/Methods/Resources/ReadResource.php
- src/Client/Methods/Tools/CallTool.php → src/mcp/src/Client/Methods/Tools/CallTool.php
- src/Client/Methods/Tools/ListTools.php → src/mcp/src/Client/Methods/Tools/ListTools.php
- src/Client/NegotiatedConnection.php → src/mcp/src/Client/NegotiatedConnection.php
- src/Client/OAuth/AuthServerDiscovery.php → src/mcp/src/Client/OAuth/AuthServerDiscovery.php
- src/Client/OAuth/AuthServerMetadata.php → src/mcp/src/Client/OAuth/AuthServerMetadata.php
- src/Client/OAuth/ClientRegistration.php → src/mcp/src/Client/OAuth/ClientRegistration.php
- src/Client/OAuth/Concerns/InteractsWithOAuthEndpoints.php → src/mcp/src/Client/OAuth/Concerns/InteractsWithOAuthEndpoints.php
- src/Client/OAuth/DiscoveryResult.php → src/mcp/src/Client/OAuth/DiscoveryResult.php
- src/Client/OAuth/DynamicClientRegistration.php → src/mcp/src/Client/OAuth/DynamicClientRegistration.php
- src/Client/OAuth/Enums/TokenEndpointAuthMethod.php → src/mcp/src/Client/OAuth/Enums/TokenEndpointAuthMethod.php
- src/Client/OAuth/OAuthClient.php → src/mcp/src/Client/OAuth/OAuthClient.php
- src/Client/OAuth/OAuthConfig.php → src/mcp/src/Client/OAuth/OAuthConfig.php
- src/Client/OAuth/OAuthRouteRegistrar.php → src/mcp/src/Client/OAuth/OAuthRouteRegistrar.php
- src/Client/OAuth/Pkce.php → src/mcp/src/Client/OAuth/Pkce.php
- src/Client/OAuth/TokenSet.php → src/mcp/src/Client/OAuth/TokenSet.php
- src/Client/OAuth/WwwAuthenticateChallenge.php → src/mcp/src/Client/OAuth/WwwAuthenticateChallenge.php
- src/Client/Primitives/Prompt.php → src/mcp/src/Client/Primitives/Prompt.php
- src/Client/Primitives/Resource.php → src/mcp/src/Client/Primitives/Resource.php
- src/Client/Primitives/Tool.php → src/mcp/src/Client/Primitives/Tool.php
- src/Client/Protocol.php → src/mcp/src/Client/Protocol.php
- src/Client/ResponseCache.php → src/mcp/src/Client/ResponseCache.php
- src/Client/Schema/DiscoverResult.php → src/mcp/src/Client/Schema/DiscoverResult.php
- src/Client/Schema/InitializeResult.php → src/mcp/src/Client/Schema/InitializeResult.php
- src/Client/Schema/PromptResult.php → src/mcp/src/Client/Schema/PromptResult.php
- src/Client/Schema/ResourceReadResult.php → src/mcp/src/Client/Schema/ResourceReadResult.php
- src/Client/Schema/ToolResult.php → src/mcp/src/Client/Schema/ToolResult.php
- src/Client/Transport/HttpTransport.php → src/mcp/src/Client/Transport/HttpTransport.php
- src/Client/Transport/StdioTransport.php → src/mcp/src/Client/Transport/StdioTransport.php
- src/Client/Transport/TransportFactory.php → src/mcp/src/Client/Transport/TransportFactory.php
- src/Console/Commands/InspectorCommand.php → src/mcp/src/Console/Commands/InspectorCommand.php
- src/Console/Commands/MakeAppResourceCommand.php → src/mcp/src/Console/Commands/MakeAppResourceCommand.php
- src/Console/Commands/MakePromptCommand.php → src/mcp/src/Console/Commands/MakePromptCommand.php
- src/Console/Commands/MakeResourceCommand.php → src/mcp/src/Console/Commands/MakeResourceCommand.php
- src/Console/Commands/MakeServerCommand.php → src/mcp/src/Console/Commands/MakeServerCommand.php
- src/Console/Commands/MakeToolCommand.php → src/mcp/src/Console/Commands/MakeToolCommand.php
- src/Console/Commands/StartCommand.php → src/mcp/src/Console/Commands/StartCommand.php
- src/Facades/Mcp.php → src/mcp/src/Facades/Mcp.php
- src/Response.php → src/mcp/src/Response.php
- src/ResponseFactory.php → src/mcp/src/ResponseFactory.php
- src/Server.php → src/mcp/src/Server.php
- src/Server/Annotations/Audience.php → src/mcp/src/Server/Annotations/Audience.php
- src/Server/Annotations/LastModified.php → src/mcp/src/Server/Annotations/LastModified.php
- src/Server/Annotations/Priority.php → src/mcp/src/Server/Annotations/Priority.php
- src/Server/Attributes/Instructions.php → src/mcp/src/Server/Attributes/Instructions.php
- src/Server/Attributes/Version.php → src/mcp/src/Server/Attributes/Version.php
- src/Server/Completions/ArrayCompletionResponse.php → src/mcp/src/Server/Completions/ArrayCompletionResponse.php
- src/Server/Completions/CompletionHelper.php → src/mcp/src/Server/Completions/CompletionHelper.php
- src/Server/Completions/CompletionResponse.php → src/mcp/src/Server/Completions/CompletionResponse.php
- src/Server/Completions/DirectCompletionResponse.php → src/mcp/src/Server/Completions/DirectCompletionResponse.php
- src/Server/Completions/EnumCompletionResponse.php → src/mcp/src/Server/Completions/EnumCompletionResponse.php
- src/Server/Contracts/Completable.php → src/mcp/src/Server/Contracts/Completable.php
- src/Server/Contracts/Errable.php → src/mcp/src/Server/Contracts/Errable.php
- src/Server/Contracts/Method.php → src/mcp/src/Server/Contracts/Method.php
- src/Server/Contracts/Transport.php → src/mcp/src/Server/Contracts/Transport.php
- src/Server/Http/Controllers/OAuthRegisterController.php → src/mcp/src/Server/Http/Controllers/OAuthRegisterController.php
- src/Server/McpServiceProvider.php → src/mcp/src/Server/McpServiceProvider.php
- src/Server/Methods/CallTool.php → src/mcp/src/Server/Methods/CallTool.php
- src/Server/Methods/CompletionComplete.php → src/mcp/src/Server/Methods/CompletionComplete.php
- src/Server/Methods/Concerns/InteractsWithResponses.php → src/mcp/src/Server/Methods/Concerns/InteractsWithResponses.php
- src/Server/Methods/Concerns/ResolvesPrompts.php → src/mcp/src/Server/Methods/Concerns/ResolvesPrompts.php
- src/Server/Methods/Concerns/ResolvesResources.php → src/mcp/src/Server/Methods/Concerns/ResolvesResources.php
- src/Server/Methods/Discover.php → src/mcp/src/Server/Methods/Discover.php
- src/Server/Methods/GetPrompt.php → src/mcp/src/Server/Methods/GetPrompt.php
- src/Server/Methods/Initialize.php → src/mcp/src/Server/Methods/Initialize.php
- src/Server/Methods/ListPrompts.php → src/mcp/src/Server/Methods/ListPrompts.php
- src/Server/Methods/ListResourceTemplates.php → src/mcp/src/Server/Methods/ListResourceTemplates.php
- src/Server/Methods/ListResources.php → src/mcp/src/Server/Methods/ListResources.php
- src/Server/Methods/ListTools.php → src/mcp/src/Server/Methods/ListTools.php
- src/Server/Methods/Listen.php → src/mcp/src/Server/Methods/Listen.php
- src/Server/Methods/Ping.php → src/mcp/src/Server/Methods/Ping.php
- src/Server/Methods/ReadResource.php → src/mcp/src/Server/Methods/ReadResource.php
- src/Server/Middleware/AddWwwAuthenticateHeader.php → src/mcp/src/Server/Middleware/AddWwwAuthenticateHeader.php
- src/Server/Middleware/ReorderJsonAccept.php → src/mcp/src/Server/Middleware/ReorderJsonAccept.php
- src/Server/Middleware/ValidateMcpHeaders.php → src/mcp/src/Server/Middleware/ValidateMcpHeaders.php
- src/Server/Pagination/CursorPaginator.php → src/mcp/src/Server/Pagination/CursorPaginator.php
- src/Server/Registrar.php → src/mcp/src/Server/Registrar.php
- src/Server/ServerContext.php → src/mcp/src/Server/ServerContext.php
- src/Server/Testing/PendingTestResponse.php → src/mcp/src/Server/Testing/PendingTestResponse.php
- src/Server/Testing/TestListResponse.php → src/mcp/src/Server/Testing/TestListResponse.php
- src/Server/Testing/TestResponse.php → src/mcp/src/Server/Testing/TestResponse.php
- src/Server/ToolInvoker.php → src/mcp/src/Server/ToolInvoker.php
- src/Server/Tools/Annotations/IsDestructive.php → src/mcp/src/Server/Tools/Annotations/IsDestructive.php
- src/Server/Tools/Annotations/IsIdempotent.php → src/mcp/src/Server/Tools/Annotations/IsIdempotent.php
- src/Server/Tools/Annotations/IsOpenWorld.php → src/mcp/src/Server/Tools/Annotations/IsOpenWorld.php
- src/Server/Tools/Annotations/IsReadOnly.php → src/mcp/src/Server/Tools/Annotations/IsReadOnly.php
- src/Server/Tools/ExecuteTools.php → src/mcp/src/Server/Tools/ExecuteTools.php
- src/Server/Tools/SearchTools.php → src/mcp/src/Server/Tools/SearchTools.php
- src/Server/Tools/ToolSearch.php → src/mcp/src/Server/Tools/ToolSearch.php
- src/Server/Transport/FakeTransporter.php → src/mcp/src/Server/Transport/FakeTransporter.php
- src/Server/Transport/HttpTransport.php → src/mcp/src/Server/Transport/HttpTransport.php
- src/Server/Transport/StdioTransport.php → src/mcp/src/Server/Transport/StdioTransport.php
- src/Support/MirroredParameter.php → src/mcp/src/Support/MirroredParameter.php
- src/Support/MirroredParameterType.php → src/mcp/src/Support/MirroredParameterType.php
- src/Support/MirroredParameters.php → src/mcp/src/Support/MirroredParameters.php
- src/Support/SchemaPath.php → src/mcp/src/Support/SchemaPath.php
- src/Support/ValidationMessages.php → src/mcp/src/Support/ValidationMessages.php
- src/WebClient.php → src/mcp/src/WebClient.php

## Tests and fixtures

- tests/ArchTest.php → tests/Mcp/ArchTest.php — Translate applicable architecture checks
- tests/Feature/Client/HandshakeNegotiationTest.php → tests/Mcp/Feature/Client/HandshakeNegotiationTest.php
- tests/Feature/Client/OAuthCallbackRouteTest.php → tests/Mcp/Feature/Client/OAuthCallbackRouteTest.php
- tests/Feature/CompletionTest.php → tests/Mcp/Feature/CompletionTest.php
- tests/Feature/Console/MakeAppResourceCommandTest.php → tests/Mcp/Feature/Console/MakeAppResourceCommandTest.php
- tests/Feature/Console/MakePromptCommandTest.php → tests/Mcp/Feature/Console/MakePromptCommandTest.php
- tests/Feature/Console/MakeResourceCommandTest.php → tests/Mcp/Feature/Console/MakeResourceCommandTest.php
- tests/Feature/Console/MakeServerCommandTest.php → tests/Mcp/Feature/Console/MakeServerCommandTest.php
- tests/Feature/Console/MakeToolCommandTest.php → tests/Mcp/Feature/Console/MakeToolCommandTest.php
- tests/Feature/Console/StartCommandTest.php → tests/Mcp/Feature/Console/StartCommandTest.php
- tests/Feature/CustomRequestTest.php → tests/Mcp/Feature/CustomRequestTest.php
- tests/Feature/Server/CachingTest.php → tests/Mcp/Feature/Server/CachingTest.php
- tests/Feature/Server/HttpTransportStreamTest.php → tests/Mcp/Feature/Server/HttpTransportStreamTest.php
- tests/Feature/Server/Middleware/ValidateMcpHeadersTest.php → tests/Mcp/Feature/Server/Middleware/ValidateMcpHeadersTest.php
- tests/Feature/Server/OAuthChallengeTest.php → tests/Mcp/Feature/Server/OAuthChallengeTest.php
- tests/Feature/Server/SubscriptionsTest.php → tests/Mcp/Feature/Server/SubscriptionsTest.php
- tests/Feature/Testing/Prompts/AssertActingAsTest.php → tests/Mcp/Feature/Testing/Prompts/AssertActingAsTest.php
- tests/Feature/Testing/Prompts/AssertErrorsTest.php → tests/Mcp/Feature/Testing/Prompts/AssertErrorsTest.php
- tests/Feature/Testing/Prompts/AssertMetadataTest.php → tests/Mcp/Feature/Testing/Prompts/AssertMetadataTest.php
- tests/Feature/Testing/Prompts/AssertNotRegisteredTest.php → tests/Mcp/Feature/Testing/Prompts/AssertNotRegisteredTest.php
- tests/Feature/Testing/Prompts/AssertNotificationTest.php → tests/Mcp/Feature/Testing/Prompts/AssertNotificationTest.php
- tests/Feature/Testing/Prompts/AssertRegisteredTest.php → tests/Mcp/Feature/Testing/Prompts/AssertRegisteredTest.php
- tests/Feature/Testing/Prompts/AssertSeeTest.php → tests/Mcp/Feature/Testing/Prompts/AssertSeeTest.php
- tests/Feature/Testing/Resources/AssertActingAsTest.php → tests/Mcp/Feature/Testing/Resources/AssertActingAsTest.php
- tests/Feature/Testing/Resources/AssertErrorsTest.php → tests/Mcp/Feature/Testing/Resources/AssertErrorsTest.php
- tests/Feature/Testing/Resources/AssertMetadataTest.php → tests/Mcp/Feature/Testing/Resources/AssertMetadataTest.php
- tests/Feature/Testing/Resources/AssertNotRegisteredTest.php → tests/Mcp/Feature/Testing/Resources/AssertNotRegisteredTest.php
- tests/Feature/Testing/Resources/AssertNotificationTest.php → tests/Mcp/Feature/Testing/Resources/AssertNotificationTest.php
- tests/Feature/Testing/Resources/AssertRegisteredTest.php → tests/Mcp/Feature/Testing/Resources/AssertRegisteredTest.php
- tests/Feature/Testing/Resources/AssertSeeTest.php → tests/Mcp/Feature/Testing/Resources/AssertSeeTest.php
- tests/Feature/Testing/Resources/UriTemplateTest.php → tests/Mcp/Feature/Testing/Resources/UriTemplateTest.php
- tests/Feature/Testing/Tools/AssertActingAsTest.php → tests/Mcp/Feature/Testing/Tools/AssertActingAsTest.php
- tests/Feature/Testing/Tools/AssertErrorsTest.php → tests/Mcp/Feature/Testing/Tools/AssertErrorsTest.php
- tests/Feature/Testing/Tools/AssertMetadataTest.php → tests/Mcp/Feature/Testing/Tools/AssertMetadataTest.php
- tests/Feature/Testing/Tools/AssertNotRegisteredTest.php → tests/Mcp/Feature/Testing/Tools/AssertNotRegisteredTest.php
- tests/Feature/Testing/Tools/AssertNotificationTest.php → tests/Mcp/Feature/Testing/Tools/AssertNotificationTest.php
- tests/Feature/Testing/Tools/AssertRegisteredTest.php → tests/Mcp/Feature/Testing/Tools/AssertRegisteredTest.php
- tests/Feature/Testing/Tools/AssertSeeTest.php → tests/Mcp/Feature/Testing/Tools/AssertSeeTest.php
- tests/Feature/Testing/Tools/AssertStructuredContentTest.php → tests/Mcp/Feature/Testing/Tools/AssertStructuredContentTest.php
- tests/Fixtures/AnnotatedResource.php → tests/Mcp/Fixtures/AnnotatedResource.php
- tests/Fixtures/ArrayTransport.php → tests/Mcp/Fixtures/ArrayTransport.php
- tests/Fixtures/CacheableResource.php → tests/Mcp/Fixtures/CacheableResource.php
- tests/Fixtures/Client/FakeTransport.php → tests/Mcp/Fixtures/Client/FakeTransport.php
- tests/Fixtures/Client/OAuthCallbackController.php → tests/Mcp/Fixtures/Client/OAuthCallbackController.php
- tests/Fixtures/Client/ThrowingTransport.php → tests/Mcp/Fixtures/Client/ThrowingTransport.php
- tests/Fixtures/Clock.php → tests/Mcp/Fixtures/Clock.php
- tests/Fixtures/CurrentTimeTool.php → tests/Mcp/Fixtures/CurrentTimeTool.php
- tests/Fixtures/CustomMethodHandler.php → tests/Mcp/Fixtures/CustomMethodHandler.php
- tests/Fixtures/CustomPassportClient.php → tests/Mcp/Fixtures/CustomPassportClient.php
- tests/Fixtures/DailyPlanResource.php → tests/Mcp/Fixtures/DailyPlanResource.php
- tests/Fixtures/ExampleResourceTemplate.php → tests/Mcp/Fixtures/ExampleResourceTemplate.php
- tests/Fixtures/ExampleServer.php → tests/Mcp/Fixtures/ExampleServer.php
- tests/Fixtures/GoingToFailPrompt.php → tests/Mcp/Fixtures/GoingToFailPrompt.php
- tests/Fixtures/LastLogLineResource.php → tests/Mcp/Fixtures/LastLogLineResource.php
- tests/Fixtures/PassportClient.php → tests/Mcp/Fixtures/PassportClient.php
- tests/Fixtures/PassportClientRepository.php → tests/Mcp/Fixtures/PassportClientRepository.php
- tests/Fixtures/PassportPassport.php → tests/Mcp/Fixtures/PassportPassport.php
- tests/Fixtures/PromptWithResultMetaPrompt.php → tests/Mcp/Fixtures/PromptWithResultMetaPrompt.php
- tests/Fixtures/RecentMeetingRecordingResource.php → tests/Mcp/Fixtures/RecentMeetingRecordingResource.php
- tests/Fixtures/ResourceLinkTool.php → tests/Mcp/Fixtures/ResourceLinkTool.php
- tests/Fixtures/ResourceWithResultMetaResource.php → tests/Mcp/Fixtures/ResourceWithResultMetaResource.php
- tests/Fixtures/ResponseFactoryWithStructuredContentTool.php → tests/Mcp/Fixtures/ResponseFactoryWithStructuredContentTool.php
- tests/Fixtures/ReviewMyCodePrompt.php → tests/Mcp/Fixtures/ReviewMyCodePrompt.php
- tests/Fixtures/SayHiTool.php → tests/Mcp/Fixtures/SayHiTool.php
- tests/Fixtures/SayHiTwiceTool.php → tests/Mcp/Fixtures/SayHiTwiceTool.php
- tests/Fixtures/SayHiWithMetaTool.php → tests/Mcp/Fixtures/SayHiWithMetaTool.php
- tests/Fixtures/ServerWithDynamicTools.php → tests/Mcp/Fixtures/ServerWithDynamicTools.php
- tests/Fixtures/StreamingTool.php → tests/Mcp/Fixtures/StreamingTool.php
- tests/Fixtures/StructuredContentTool.php → tests/Mcp/Fixtures/StructuredContentTool.php
- tests/Fixtures/StructuredContentWithMetaTool.php → tests/Mcp/Fixtures/StructuredContentWithMetaTool.php
- tests/Fixtures/TellMeHiPrompt.php → tests/Mcp/Fixtures/TellMeHiPrompt.php
- tests/Fixtures/ToolWithBothMetaTool.php → tests/Mcp/Fixtures/ToolWithBothMetaTool.php
- tests/Fixtures/ToolWithOutputSchema.php → tests/Mcp/Fixtures/ToolWithOutputSchema.php
- tests/Fixtures/ToolWithResultMetaTool.php → tests/Mcp/Fixtures/ToolWithResultMetaTool.php
- tests/Fixtures/ToolWithoutOutputSchema.php → tests/Mcp/Fixtures/ToolWithoutOutputSchema.php
- tests/Fixtures/UriTemplateSummaryResource.php → tests/Mcp/Fixtures/UriTemplateSummaryResource.php
- tests/Fixtures/UriTemplateTestServer.php → tests/Mcp/Fixtures/UriTemplateTestServer.php
- tests/Fixtures/UriTemplateUserFileResource.php → tests/Mcp/Fixtures/UriTemplateUserFileResource.php
- tests/Fixtures/binary.png → tests/Mcp/Fixtures/binary.png
- tests/Unit/AttributesTest.php → tests/Mcp/Unit/AttributesTest.php
- tests/Unit/Client/CachingTest.php → tests/Mcp/Unit/Client/CachingTest.php
- tests/Unit/Client/ClientManagerTest.php → tests/Mcp/Unit/Client/ClientManagerTest.php
- tests/Unit/Client/ClientTest.php → tests/Mcp/Unit/Client/ClientTest.php
- tests/Unit/Client/HandshakeTest.php → tests/Mcp/Unit/Client/HandshakeTest.php
- tests/Unit/Client/OAuth/OAuthClientTest.php → tests/Mcp/Unit/Client/OAuth/OAuthClientTest.php
- tests/Unit/Client/OAuth/OAuthTransportTest.php → tests/Mcp/Unit/Client/OAuth/OAuthTransportTest.php
- tests/Unit/Client/PromptsTest.php → tests/Mcp/Unit/Client/PromptsTest.php
- tests/Unit/Client/ResourcesTest.php → tests/Mcp/Unit/Client/ResourcesTest.php
- tests/Unit/Client/ToolHeaderTest.php → tests/Mcp/Unit/Client/ToolHeaderTest.php
- tests/Unit/Client/ToolsTest.php → tests/Mcp/Unit/Client/ToolsTest.php
- tests/Unit/Client/Transport/HttpTransportTest.php → tests/Mcp/Unit/Client/Transport/HttpTransportTest.php
- tests/Unit/Client/Transport/TransportFactoryTest.php → tests/Mcp/Unit/Client/Transport/TransportFactoryTest.php
- tests/Unit/Completions/ArrayCompletionResponseTest.php → tests/Mcp/Unit/Completions/ArrayCompletionResponseTest.php
- tests/Unit/Completions/CompletionHelperTest.php → tests/Mcp/Unit/Completions/CompletionHelperTest.php
- tests/Unit/Completions/CompletionResponseTest.php → tests/Mcp/Unit/Completions/CompletionResponseTest.php
- tests/Unit/Completions/DirectCompletionResponseTest.php → tests/Mcp/Unit/Completions/DirectCompletionResponseTest.php
- tests/Unit/Completions/EnumCompletionResponseTest.php → tests/Mcp/Unit/Completions/EnumCompletionResponseTest.php
- tests/Unit/Console/Commands/McpInspectorCommandTest.php → tests/Mcp/Unit/Console/Commands/McpInspectorCommandTest.php
- tests/Unit/Console/Commands/StartCommandTest.php → tests/Mcp/Unit/Console/Commands/StartCommandTest.php
- tests/Unit/Methods/CallToolTest.php → tests/Mcp/Unit/Methods/CallToolTest.php
- tests/Unit/Methods/CompletionCompleteTest.php → tests/Mcp/Unit/Methods/CompletionCompleteTest.php
- tests/Unit/Methods/DiscoverAppTest.php → tests/Mcp/Unit/Methods/DiscoverAppTest.php
- tests/Unit/Methods/GetPromptTest.php → tests/Mcp/Unit/Methods/GetPromptTest.php
- tests/Unit/Methods/ListPromptsTest.php → tests/Mcp/Unit/Methods/ListPromptsTest.php
- tests/Unit/Methods/ListResourceTemplatesTest.php → tests/Mcp/Unit/Methods/ListResourceTemplatesTest.php
- tests/Unit/Methods/ListResourcesTest.php → tests/Mcp/Unit/Methods/ListResourcesTest.php
- tests/Unit/Methods/ListToolsTest.php → tests/Mcp/Unit/Methods/ListToolsTest.php
- tests/Unit/Methods/ReadAppResourceTest.php → tests/Mcp/Unit/Methods/ReadAppResourceTest.php
- tests/Unit/Methods/ReadResourceTest.php → tests/Mcp/Unit/Methods/ReadResourceTest.php
- tests/Unit/Pagination/CursorPaginatorTest.php → tests/Mcp/Unit/Pagination/CursorPaginatorTest.php
- tests/Unit/Primitive/EligibleForRegistrationTest.php → tests/Mcp/Unit/Primitive/EligibleForRegistrationTest.php
- tests/Unit/Prompts/PromptTest.php → tests/Mcp/Unit/Prompts/PromptTest.php
- tests/Unit/Resources/CallToolTest.php → tests/Mcp/Unit/Resources/CallToolTest.php
- tests/Unit/Resources/GetPromptTest.php → tests/Mcp/Unit/Resources/GetPromptTest.php
- tests/Unit/Resources/ListPromptsTest.php → tests/Mcp/Unit/Resources/ListPromptsTest.php
- tests/Unit/Resources/ListResourcesTest.php → tests/Mcp/Unit/Resources/ListResourcesTest.php
- tests/Unit/Resources/ListToolsTest.php → tests/Mcp/Unit/Resources/ListToolsTest.php
- tests/Unit/Resources/ReadResourceTest.php → tests/Mcp/Unit/Resources/ReadResourceTest.php
- tests/Unit/Resources/ResourceAnnotationsTest.php → tests/Mcp/Unit/Resources/ResourceAnnotationsTest.php
- tests/Unit/Resources/ResourceTemplateTest.php → tests/Mcp/Unit/Resources/ResourceTemplateTest.php
- tests/Unit/Resources/ResourceTest.php → tests/Mcp/Unit/Resources/ResourceTest.php
- tests/Unit/ResponseFactoryTest.php → tests/Mcp/Unit/ResponseFactoryTest.php
- tests/Unit/ResponseFromStorageTest.php → tests/Mcp/Unit/ResponseFromStorageTest.php
- tests/Unit/ResponseTest.php → tests/Mcp/Unit/ResponseTest.php
- tests/Unit/Server/McpServiceProviderTest.php → tests/Mcp/Unit/Server/McpServiceProviderTest.php
- tests/Unit/Server/Middleware/ReorderJsonAcceptTest.php → tests/Mcp/Unit/Server/Middleware/ReorderJsonAcceptTest.php
- tests/Unit/Server/Prompts/ArgumentTest.php → tests/Mcp/Unit/Server/Prompts/ArgumentTest.php
- tests/Unit/Server/RegistrarTest.php → tests/Mcp/Unit/Server/RegistrarTest.php
- tests/Unit/Server/Transport/StdioTransportTest.php → tests/Mcp/Unit/Server/Transport/StdioTransportTest.php
- tests/Unit/ServerContextTest.php → tests/Mcp/Unit/ServerContextTest.php
- tests/Unit/ServerTest.php → tests/Mcp/Unit/ServerTest.php
- tests/Unit/Support/MirroredParametersTest.php → tests/Mcp/Unit/Support/MirroredParametersTest.php
- tests/Unit/Support/ValidationMessagesTest.php → tests/Mcp/Unit/Support/ValidationMessagesTest.php
- tests/Unit/Tools/ToolAnnotationsTest.php → tests/Mcp/Unit/Tools/ToolAnnotationsTest.php
- tests/Unit/Tools/ToolSearchTest.php → tests/Mcp/Unit/Tools/ToolSearchTest.php
- tests/Unit/Tools/ToolTest.php → tests/Mcp/Unit/Tools/ToolTest.php
- tests/Unit/Transport/FakeTransporterTest.php → tests/Mcp/Unit/Transport/FakeTransporterTest.php

## Conformance harness

- dogfood/mcp/composer.json — isolated package-mode project/path repository and conformance autoloading
- dogfood/mcp/testbench.yaml — opt-in server configuration
- dogfood/mcp/workbench/app/Providers/WorkbenchServiceProvider.php — conformance route only
- dogfood/mcp/README.md — local setup, client/server commands and temporary output location

- tests/Conformance/ConformanceServer.php → dogfood/mcp/conformance/ConformanceServer.php
- tests/Conformance/Fixtures.php → dogfood/mcp/conformance/Fixtures.php
- tests/Conformance/Prompts/PromptWithArguments.php → dogfood/mcp/conformance/Prompts/PromptWithArguments.php
- tests/Conformance/Prompts/PromptWithEmbeddedResource.php → dogfood/mcp/conformance/Prompts/PromptWithEmbeddedResource.php
- tests/Conformance/Prompts/PromptWithImage.php → dogfood/mcp/conformance/Prompts/PromptWithImage.php
- tests/Conformance/Prompts/SimplePrompt.php → dogfood/mcp/conformance/Prompts/SimplePrompt.php
- tests/Conformance/Resources/StaticBinaryResource.php → dogfood/mcp/conformance/Resources/StaticBinaryResource.php
- tests/Conformance/Resources/StaticTextResource.php → dogfood/mcp/conformance/Resources/StaticTextResource.php
- tests/Conformance/Resources/TemplateResource.php → dogfood/mcp/conformance/Resources/TemplateResource.php
- tests/Conformance/Resources/WatchedResource.php → dogfood/mcp/conformance/Resources/WatchedResource.php
- tests/Conformance/Tools/AudioContentTool.php → dogfood/mcp/conformance/Tools/AudioContentTool.php
- tests/Conformance/Tools/EmbeddedResourceTool.php → dogfood/mcp/conformance/Tools/EmbeddedResourceTool.php
- tests/Conformance/Tools/ErrorHandlingTool.php → dogfood/mcp/conformance/Tools/ErrorHandlingTool.php
- tests/Conformance/Tools/ImageContentTool.php → dogfood/mcp/conformance/Tools/ImageContentTool.php
- tests/Conformance/Tools/MultipleContentTypesTool.php → dogfood/mcp/conformance/Tools/MultipleContentTypesTool.php
- tests/Conformance/Tools/SimpleTextTool.php → dogfood/mcp/conformance/Tools/SimpleTextTool.php
- tests/Conformance/client.php → dogfood/mcp/conformance/client.php
- tests/Conformance/conformance-baseline.yml → dogfood/mcp/conformance/conformance-baseline.yml
- tests/Conformance/run.sh → dogfood/mcp/conformance/run.sh
- tests/Conformance/score.php → dogfood/mcp/conformance/score.php

## Test bootstrap

- tests/Pest.php → tests/Mcp/Fixtures/helpers.php + tests/Mcp/TestCase.php — Translate shared helpers/configuration; no Pest runtime
- tests/TestCase.php → tests/Mcp/TestCase.php

## Workbench fixtures

- workbench/app/Providers/WorkbenchServiceProvider.php → tests/Mcp/Fixtures/Workbench/Providers/WorkbenchServiceProvider.php

## Package metadata and canonical documentation

- composer.json → src/mcp/composer.json — use Hypervel first-party metadata and actual dependencies
- README.md → src/mcp/README.md — minimal Hypervel package README
- UPGRADE.md → assess current contracts when porting src/docs/mcp.md; do not ship historical upgrade instructions as new-package guidance
- references/laravel/docs/mcp.md → src/docs/mcp.md — canonical page, excluding Boost guidance

## Existing Hypervel consumers and wiring

- composer.json — root autoload, replace, provider/alias discovery, required dependencies
- src/testing/src/PHPUnit/AfterEachTestSubscriber.php — optional MCP attribute and macro cleanup, including TestListResponse
- src/http/src/Client/Destinations/ResolvedDestination.php — shared real-host pin options; retain strict direct-route isolation
- tests/Http/Client/Destinations/ResolvedDestinationTest.php — shared pin formatting, IPv6/multiple addresses and literal hosts
- src/ai/src/Files/UntrustedUrl.php — reuse shared pins with buffered requests and existing proxy behavior
- tests/Ai/Feature/Files/UntrustedUrlTest.php — retain download/proxy coverage
- src/ai/src/Tools/McpServerTool.php — verify production context integration and remove obsolete ignores
- src/ai/src/Tools/McpTool.php — verify client/schema integration and remove obsolete ignores
- tests/Ai/Unit/Tools/McpServerToolTest.php — real-provider subclass/context coverage
- tests/Ai/Unit/Tools/McpToolTest.php — activate and retain supported schema behavior
- tests/Ai/Feature/McpServerToolTest.php — activate production-provider behavior
- tests/Ai/Feature/McpToolTest.php — activate client tools
- tests/Integration/Ai/McpClientTest.php — retain live-service opt-in requirements
- src/docs/ai-sdk.md — available MCP integration and links; hostname-pinning runtime requirement for remote attachments
- src/docs/documentation.md — MCP navigation
- src/docs/porting-from-laravel.md — only concrete porter actions
- docs/todo.md — resolve completed AI/MCP item; extend existing buffered-reader follow-up

## Repository infrastructure disposition

Upstream repository-level .github automation, .editorconfig, .gitattributes, .gitignore, phpstan.neon.dist, phpunit.xml.dist, pint.json and rector.php are references for the existing monorepo tools, not replacement root files. Translate relevant verification requirements into existing workflows/configuration only where needed. testbench.yaml behavior belongs in the test fixture/bootstrap and isolated dogfood/mcp project; do not replace the shared testbench setup. Omit tests/Conformance/.gitignore because logs/results live outside the source tree. CHANGELOG.md is upstream release history, not a new Hypervel changelog. art/ contains Laravel branding and is not runtime Apps content. resources/boost is globally excluded. No runtime JavaScript/view/stub is covered by those exclusions.

New regression tests and optional benchmark files follow the design plan's owning directories. Add their paths here once their concrete files are chosen; do not invent a separate class for each assertion.

