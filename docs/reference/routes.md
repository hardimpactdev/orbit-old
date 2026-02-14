# Routes

## Environment Management

- `GET /environments` - List all environments
- `GET /environments/{environment}` - Show environment (or provisioning progress if status is `provisioning`)
- `POST /environments/{environment}/test-connection` - Test SSH connection
- `GET /environments/{environment}/status` - Get orbit status
- `GET /environments/{environment}/projects` - Get projects list from CLI
- `POST /environments/{environment}/start|stop|restart` - Control orbit services
- `POST /environments/{environment}/php` - Change PHP version for a site

## Provisioning

- `GET /provision` - Show provisioning form
- `POST /provision` - Create environment and redirect to provisioning
- `POST /provision/{environment}/run` - Start provisioning (called via AJAX)
- `GET /provision/{environment}/status` - Poll provisioning status

## Worktrees

- `GET /environments/{environment}/worktrees` - List all worktrees (auto-detected from git)
- `POST /environments/{environment}/worktrees/unlink` - Remove worktree subdomain routing
- `POST /environments/{environment}/worktrees/refresh` - Re-scan for new worktrees

## Projects

- `GET /projects` - List all projects
- `GET /projects/create` - Create project form
- `GET /projects/{project}` - Show project with deployments
- `DELETE /projects/{project}` - Delete project
- `GET /projects/scan/{environment}` - Scan environment for existing projects
- `POST /projects/import/{environment}` - Import discovered project
