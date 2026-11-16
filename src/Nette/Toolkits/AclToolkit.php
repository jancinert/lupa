<?php declare(strict_types=1);

namespace Lupa\Nette\Toolkits;

use Lupa\InspectorException;
use Lupa\Masking;
use Lupa\Nette\NetteApplication;
use Lupa\Nette\PermissionProvider;
use Lupa\Tools\AppContext;
use Lupa\Tools\Arguments;
use Lupa\Tools\Schema;
use Lupa\Tools\Tool;
use Lupa\Tools\Toolkit;
use Lupa\Tools\ToolResult;
use Nette\DI\Container;
use Nette\Security\Permission;


/**
 * Roles, resources and rules of Nette\Security\Permission, and what a role may do.
 */
final class AclToolkit implements Toolkit
{
	private const Results = [
		'acl_roles' => [
			'roles' => 'object|array', // role => parents
			'resources' => 'object|array', // resource => parent
			'rules' => [[
				'role' => '?string',
				'resource' => '?string',
				'privilege' => '?string',
				'type' => 'string',
				'assertion' => 'boolean',
			]],
		],
		'acl_check' => [
			'role' => '?string',
			'resource' => '?string',
			'privilege' => '?string',
			'allowed' => 'boolean',
			'roleAncestors' => 'string[]',
			'resourceAncestors' => 'string[]',
			'rules' => [[
				'role' => '?string',
				'resource' => '?string',
				'privilege' => '?string',
				'type' => 'string',
				'assertion' => 'boolean',
			]],
		],
		'acl_matrix' => [
			'resource' => '?string',
			'privileges' => 'string[]',
			'matrix' => 'object|array', // role => privilege => allowed
		],
	];

	private const AuthorizatorTypes = ['Nette\Security\Authorizator', 'Nette\Security\IAuthorizator'];
	private const IdentifierMaps = ['roles', 'resources', 'matrix'];

	private ?PermissionProvider $provider;
	private Masking $masking;


	public function __construct(?PermissionProvider $provider = null, ?Masking $masking = null)
	{
		$this->provider = $provider;
		$this->masking = $masking ?? new Masking;
	}


	public function getTools(): array
	{
		$role = Schema::string('Role name.');
		$resource = Schema::string('Resource name; omit for all resources.');
		$tools = Tool::withResults([
			new Tool(
				'acl_roles',
				'ACL roles and resources',
				'Lists the roles with their parents, the resources with their parents, and all allow and deny rules.',
				Schema::object([]),
			),
			new Tool(
				'acl_check',
				'ACL check',
				'Answers whether a role may use a resource with a privilege, and lists the rules of the role, its parents,'
				. ' the resource and its parents that decide it.',
				Schema::object([
					'role' => $role,
					'resource' => $resource,
					'privilege' => Schema::string('Privilege; omit for all privileges.'),
				], ['role']),
			),
			new Tool(
				'acl_matrix',
				'ACL matrix',
				'For one resource, shows for every role which of the privileges named in the rules it is allowed.',
				Schema::object(['resource' => Schema::string('Resource name.')], ['resource']),
			),
		], self::Results);
		foreach ($tools as $tool) {
			$tool->identifierMaps = self::IdentifierMaps;
		}
		return $tools;
	}


	public function call(string $tool, array $arguments, AppContext $context): ToolResult
	{
		$arguments = new Arguments($arguments);
		$permission = $this->getPermission(NetteApplication::getContainerOf($context->application));
		[$roles, $resources, $rules] = \Closure::bind(
			fn(): array => [$this->roles, $this->resources, $this->rules],
			$permission,
			Permission::class,
		)();
		$rules = self::flattenRules($rules);

		switch ($tool) {
			case 'acl_roles':
				$data = [
					'roles' => array_map(fn(array $role) => array_keys($role['parents']), $roles),
					'resources' => array_map(fn(array $resource) => $resource['parent'], $resources),
					'rules' => $rules,
				];
				$result = new ToolResult(
					$data,
					sprintf('%d role(s), %d resource(s), %d rule(s).', count($roles), count($resources), count($rules)),
				);
				break;

			case 'acl_check':
				$result = $this->check(
					$permission,
					$rules,
					$arguments->string('role'),
					$arguments->optionalString('resource'),
					$arguments->optionalString('privilege'),
				);
				break;

			case 'acl_matrix':
				$result = $this->matrix($permission, $rules, array_keys($roles), $arguments->string('resource'));
				break;

			default:
				throw new InspectorException("Unknown tool '$tool'.");
		}
		return new ToolResult($this->masking->maskArray($result->data, self::IdentifierMaps), $this->masking->maskText($result->text));
	}


	/**
	 * @param list<array{role: ?string, resource: ?string, privilege: ?string, type: string, assertion: bool}> $rules
	 */
	private function check(Permission $permission, array $rules, string $role, ?string $resource, ?string $privilege): ToolResult
	{
		self::assertKnown($permission, $role, $resource);
		$roles = array_merge([$role], self::getAncestors($permission, $role));
		$resources = [$resource];
		for (
			$current = $resource;
			$current !== null && ($parent = self::getResourceParent($permission, $current)) !== null;
			$current = $parent
		) {
			$resources[] = $parent;
		}

		$relevant = array_values(array_filter($rules, fn(array $rule) => ($rule['role'] === null || in_array($rule['role'], $roles, true))
			&& ($rule['resource'] === null || in_array($rule['resource'], $resources, true))
			&& ($rule['privilege'] === null || $privilege === null || $rule['privilege'] === $privilege)));
		$allowed = $permission->isAllowed($role, $resource, $privilege);
		$data = [
			'role' => $role,
			'resource' => $resource,
			'privilege' => $privilege,
			'allowed' => $allowed,
			'roleAncestors' => array_slice($roles, 1),
			'resourceAncestors' => array_values(array_filter(array_slice($resources, 1))),
			'rules' => $relevant,
		];
		return new ToolResult($data, sprintf(
			'%s %s %s on %s.',
			$role,
			$allowed ? 'is allowed' : 'is denied',
			$privilege ?? 'all privileges',
			$resource ?? 'all resources',
		));
	}


	/**
	 * @param list<array{role: ?string, resource: ?string, privilege: ?string, type: string, assertion: bool}> $rules
	 * @param list<string> $roles
	 */
	private function matrix(Permission $permission, array $rules, array $roles, string $resource): ToolResult
	{
		self::assertKnown($permission, null, $resource);
		$privileges = [];
		foreach ($rules as $rule) {
			if ($rule['privilege'] !== null && ($rule['resource'] === null || $rule['resource'] === $resource)) {
				$privileges[$rule['privilege']] = true;
			}
		}

		$privileges = array_keys($privileges);
		sort($privileges);
		$matrix = [];
		foreach ($roles as $role) {
			$row = ['*' => $permission->isAllowed($role, $resource)];
			foreach ($privileges as $privilege) {
				$row[$privilege] = $permission->isAllowed($role, $resource, $privilege);
			}

			$matrix[$role] = $row;
		}

		return new ToolResult(
			['resource' => $resource, 'privileges' => $privileges, 'matrix' => $matrix],
			sprintf('%d role(s) × %d privilege(s) on %s; "*" means all privileges.', count($roles), count($privileges), $resource),
		);
	}


	private function getPermission(Container $container): Permission
	{
		if (!class_exists(Permission::class)) {
			throw new InspectorException('The application does not use nette/security.');
		} elseif ($this->provider) {
			return $this->provider->getPermission($container);
		}

		foreach (self::AuthorizatorTypes as $type) {
			$authorizator = interface_exists($type) ? $container->getByType($type, false) : null;
			if ($authorizator instanceof Permission) {
				return $authorizator;
			}
		}

		throw new InspectorException('The container has no Nette\Security\Permission service. If the application builds its ACL elsewhere,'
			. ' register a Lupa\Nette\PermissionProvider service in lupa.neon.');
	}


	/**
	 * Rules of Permission's internal tree as a flat list; null means all roles, resources or privileges.
	 * @param array<string, mixed> $tree
	 * @return list<array{role: ?string, resource: ?string, privilege: ?string, type: string, assertion: bool}>
	 */
	private static function flattenRules(array $tree): array
	{
		$result = [];
		$byResource = ['' => $tree['allResources']] + (array) ($tree['byResource'] ?? []);
		foreach ($byResource as $resource => $resourceRules) {
			$resourceRules = (array) $resourceRules;
			$byRole = ['' => $resourceRules['allRoles'] ?? null] + (array) ($resourceRules['byRole'] ?? []);
			foreach ($byRole as $role => $roleRules) {
				if (!is_array($roleRules)) {
					continue;
				}

				$roleRules = (array) $roleRules;
				$byPrivilege = ['' => $roleRules['allPrivileges'] ?? null] + (array) ($roleRules['byPrivilege'] ?? []);
				foreach ($byPrivilege as $privilege => $rule) {
					if (is_array($rule) && isset($rule['type'])) {
						$result[] = [
							'role' => $role === '' ? null : (string) $role,
							'resource' => $resource === '' ? null : (string) $resource,
							'privilege' => $privilege === '' ? null : (string) $privilege,
							'type' => $rule['type'] === true ? 'allow' : 'deny', // Authorizator::ALLOW, renamed to Allow in 3.2
							'assertion' => isset($rule['assert']),
						];
					}
				}
			}
		}

		return $result;
	}


	/**
	 * @return list<string>  parents, grandparents and so on, breadth first
	 */
	private static function getAncestors(Permission $permission, string $role): array
	{
		$result = [];
		$queue = $permission->getRoleParents($role);
		while ($queue) {
			$parent = array_shift($queue);
			if (!in_array($parent, $result, true)) {
				$result[] = $parent;
				$queue = array_merge($queue, $permission->getRoleParents($parent));
			}
		}

		return $result;
	}


	private static function getResourceParent(Permission $permission, string $resource): ?string
	{
		$resources = \Closure::bind(fn(): array => $this->resources, $permission, Permission::class)();
		return $resources[$resource]['parent'] ?? null;
	}


	private static function assertKnown(Permission $permission, ?string $role, ?string $resource): void
	{
		if ($role !== null && !$permission->hasRole($role)) {
			throw new InspectorException("Role '$role' does not exist; known roles: " . implode(', ', $permission->getRoles()) . '.');
		} elseif ($resource !== null && !$permission->hasResource($resource)) {
			throw new InspectorException("Resource '$resource' does not exist; known resources: " . implode(', ', $permission->getResources()) . '.');
		}
	}
}
