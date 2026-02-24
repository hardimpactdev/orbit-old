**Second Opinion: Project Review (CLI/Core/App)**
Static review only; I did not execute the test suite.

**Critical Findings**
1. `RCE risk in service-control APIs (unauthenticated routes + unescaped shell composition).`  
`packages/app/src/OrbitAppServiceProvider.php:94` mounts API routes with `api` middleware only.  
`packages/app/routes/api.php:40` exposes service-control endpoints publicly in that group.  
`packages/app/src/Http/Controllers/NodeServiceController.php:60` forwards raw `{service}` route params directly.  
`packages/core/src/Services/OrbitCli/ServiceControlService.php:69` / `packages/core/src/Services/OrbitCli/ServiceControlService.php:81` / `packages/core/src/Services/OrbitCli/ServiceControlService.php:96` / `packages/core/src/Services/OrbitCli/ServiceControlService.php:108` / `packages/core/src/Services/OrbitCli/ServiceControlService.php:484` / `packages/core/src/Services/OrbitCli/ServiceControlService.php:496` interpolate those values into shell commands.  
`packages/core/src/Services/OrbitCli/ServiceControlService.php:522` allows fallback container names from arbitrary input.  
This is the biggest problem in the repo.

2. `Remote deploy command injection/path injection via slug/name.`  
`packages/app/src/Mcp/Tools/Gateway/GatewayRegisterProjectTool.php:46` accepts arbitrary `slug` as plain string.  
`packages/app/src/Mcp/Tools/Gateway/GatewayDeployTool.php:52` accepts arbitrary `name` for legacy flow.  
`packages/core/src/Services/RemoteDeploy/RemoteDeployContext.php:29` builds paths directly from slug.  
`packages/core/src/Services/RemoteDeploy/RemoteDeploymentOrchestrator.php:81` / `packages/core/src/Services/RemoteDeploy/RemoteDeploymentOrchestrator.php:100` / `packages/core/src/Services/RemoteDeploy/RemoteDeploymentOrchestrator.php:162` and others run unescaped interpolated shell commands with those paths.  
`packages/core/src/Services/RemoteDeploy/RemoteCaddyManager.php:116` also executes shell commands with unescaped slug-derived filenames.

**High Findings**
1. `Secret leakage through CLI arguments/process list.`  
`packages/cli/app/Commands/Gateway/GatewaySetPasswordCommand.php:12` requires password as CLI arg.  
`packages/cli/app/Commands/SetupGatewayCommand.php:82` sends WG password via SSH command arg.  
`packages/core/src/Services/RemoteDeploy/RemoteDeploymentOrchestrator.php:346` / `packages/core/src/Services/RemoteDeploy/RemoteDeploymentOrchestrator.php:349` embed Cloudflare token in shell command content.  
This conflicts with your own security rule to avoid secrets in CLI args.

2. `PHP preflight check is broken when php_version is provided.`  
`packages/app/src/Mcp/Tools/Gateway/GatewayDeployTool.php:245` uses `escapeshellarg("~/.config/...")`; quoted `~` does not expand.  
`packages/app/src/Mcp/Tools/Gateway/GatewayDeployTool.php:246` therefore can falsely report missing sockets.

**Medium Findings**
1. `App gateway unit tests are stale against core API.`  
`packages/core/src/Services/DeploymentService.php:22` now requires 3 constructor deps.  
`packages/app/tests/Unit/Mcp/Tools/Gateway/GatewayDeployToolTest.php:20` and `packages/app/tests/Unit/Mcp/Tools/Gateway/GatewaySyncUndeployToolsTest.php:19` instantiate with 2 deps.  
This indicates app-side tests are drifting and likely not exercising current behavior.

2. `MCP access control is IPv4-only.`  
`packages/app/src/Http/Middleware/McpAccessControl.php:19` allows `127.0.0.1` but not `::1`; `ip2long` at `packages/app/src/Http/Middleware/McpAccessControl.php:68` is IPv4-only.  
`packages/app/tests/Feature/McpEndpointSecurityTest.php:59` only tests IPv4 localhost.

**What I’d Change First (in order)**
1. Lock down API routes with strong auth/IP middleware before anything else.  
2. Replace all shell string interpolation with escaped args and allowlists for `service`, `slug`, `name`, and `domain`.  
3. Redesign secret flows to stdin/file-based transfer (`gateway:set-password` should support stdin; remove password args).  
4. Fix the PHP socket preflight path check (`~` expansion bug).  
5. Repair broken app tests and add exploit-focused tests for route input sanitization and auth boundaries.

**Open Questions**
1. Are `/api/*` endpoints guaranteed private by network policy in every deployment, or are they ever internet-exposed?  
2. Do you need backward compatibility for non-slug-safe project names/slugs, or can you enforce strict slug regex immediately?
