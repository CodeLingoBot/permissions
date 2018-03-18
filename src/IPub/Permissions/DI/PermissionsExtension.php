<?php
/**
 * PermissionsExtension.php
 *
 * @copyright      More in license.md
 * @license        https://www.ipublikuj.eu
 * @author         Adam Kadlec https://www.ipublikuj.eu
 * @package        iPublikuj:Permissions!
 * @subpackage     DI
 * @since          1.0.0
 *
 * @date           10.10.14
 */

declare(strict_types = 1);

namespace IPub\Permissions\DI;

use Nette;
use Nette\DI;
use Nette\PhpGenerator as Code;
use Nette\Security as NS;
use Nette\Utils;

use IPub\Permissions;
use IPub\Permissions\Access;
use IPub\Permissions\Entities;
use IPub\Permissions\Exceptions;
use IPub\Permissions\Providers;
use IPub\Permissions\Security;

/**
 * Permission extension container
 *
 * @package        iPublikuj:Permissions!
 * @subpackage     DI
 *
 * @author         Adam Kadlec <adam.kadlec@ipublikuj.eu>
 */
final class PermissionsExtension extends DI\CompilerExtension
{
	/**
	 * @var array
	 */
	private $defaults = [
		'annotation'  => TRUE,
		'redirectUrl' => NULL,
		'providers'   => [
			'roles'       => TRUE,
			'resources'   => TRUE,
			'permissions' => TRUE,
		],
	];

	public function loadConfiguration() : void
	{
		// Get container builder
		$builder = $this->getContainerBuilder();
		// Get extension configuration
		$configuration = $this->getConfig($this->defaults);

		// Application permissions
		$builder->addDefinition($this->prefix('permissions'))
			->setType(Security\Permission::class);

		$builder->addDefinition($this->prefix('config'))
			->setType(Permissions\Configuration::class)
			->setArguments([
				$configuration['redirectUrl'],
			]);

		/**
		 * Data providers
		 */

		if ($configuration['providers']['roles'] === TRUE) {
			$builder->addDefinition($this->prefix('providers.roles'))
				->setType(Providers\RolesProvider::class);

		} elseif (is_string($configuration['providers']['roles']) && class_exists($configuration['providers']['roles'])) {
			$builder->addDefinition($this->prefix('providers.roles'))
				->setType($configuration['providers']['roles']);
		}

		if ($configuration['providers']['resources'] === TRUE) {
			$builder->addDefinition($this->prefix('providers.resources'))
				->setType(Providers\ResourcesProvider::class);

		} elseif (is_string($configuration['providers']['resources']) && class_exists($configuration['providers']['resources'])) {
			$builder->addDefinition($this->prefix('providers.resources'))
				->setType($configuration['providers']['resources']);
		}

		if ($configuration['providers']['permissions'] === TRUE) {
			$builder->addDefinition($this->prefix('providers.permissions'))
				->setType(Providers\PermissionsProvider::class);

		} elseif (is_string($configuration['providers']['permissions']) && class_exists($configuration['providers']['permissions'])) {
			$builder->addDefinition($this->prefix('providers.permissions'))
				->setType($configuration['providers']['permissions']);
		}

		/**
		 * Access checkers
		 */

		// Check if annotation checker is enabled
		if ($configuration['annotation'] === TRUE) {
			// Annotation access checkers
			$builder->addDefinition($this->prefix('checkers.annotation'))
				->setType(Access\AnnotationChecker::class);
		}

		// Latte access checker
		$builder->addDefinition($this->prefix('checkers.latte'))
			->setType(Access\LatteChecker::class);

		// Link access checker
		$builder->addDefinition($this->prefix('checkers.link'))
			->setType(Access\LinkChecker::class);
	}

	/**
	 * {@inheritdoc}
	 */
	public function beforeCompile() : void
	{
		parent::beforeCompile();

		// Get container builder
		$builder = $this->getContainerBuilder();

		// Get acl permissions service
		$permissionsProvider = $builder->findByType(Providers\IPermissionsProvider::class);
		$permissionsProvider = reset($permissionsProvider);

		// Get acl resources service
		$resourcesProvider = $builder->findByType(Providers\IResourcesProvider::class);
		$resourcesProvider = reset($resourcesProvider);

		// Check all extensions and search for permissions provider
		foreach ($this->compiler->getExtensions() as $extension) {
			if (!$extension instanceof IPermissionsProvider) {
				continue;
			}

			// Get permissions & details
			$this->registerPermissionsResources($extension->getPermissions(), $resourcesProvider, $permissionsProvider);
		}

		// Install extension latte macros
		$latteFactory = $builder->getDefinition($builder->getByType(Nette\Bridges\ApplicationLatte\ILatteFactory::class) ?: 'nette.latteFactory');

		$latteFactory
			->addSetup('IPub\Permissions\Latte\Macros::install(?->getCompiler())', ['@self']);
	}

	/**
	 * @param Nette\Configurator $config
	 * @param string $extensionName
	 *
	 * @return void
	 */
	public static function register(Nette\Configurator $config, $extensionName = 'permissions') : void
	{
		$config->onCompile[] = function (Nette\Configurator $config, Nette\DI\Compiler $compiler) use ($extensionName) {
			$compiler->addExtension($extensionName, new PermissionsExtension());
		};
	}

	/**
	 * @param array $permissions
	 * @param DI\ServiceDefinition|NULL $resourcesProvider
	 * @param DI\ServiceDefinition|NULL $permissionsProvider
	 *
	 * @return void
	 *
	 * @throws Exceptions\InvalidArgumentException
	 */
	private function registerPermissionsResources(
		array $permissions,
		DI\ServiceDefinition $resourcesProvider = NULL,
		DI\ServiceDefinition $permissionsProvider = NULL
	) : void {
		foreach ($permissions as $permission => $details) {
			if (is_string($permission) && Utils\Strings::contains($permission, Entities\IPermission::DELIMITER)) {
				// Parse resource & privilege from permission
				list($resource, $privilege) = explode(Entities\IPermission::DELIMITER, $permission);

				// Remove white spaces
				$resource = Utils\Strings::trim($resource);
				$privilege = Utils\Strings::trim($privilege);

				$resource = new Entities\Resource($resource);

			} elseif (is_array($details)) {
				if (!isset($details['resource']) || !isset($details['privilege'])) {
					throw new Exceptions\InvalidArgumentException('Permission must include resource & privilege.');
				}

				// Remove white spaces
				$resource = Utils\Strings::trim($details['resource']);
				$privilege = Utils\Strings::trim($details['privilege']);

				$resource = new Entities\Resource($resource);

				$details = NULL;

			} elseif ($details instanceof Entities\IPermission) {
				$resource = $details->getResource();
				$privilege = $details->getPrivilege();

				$details = NULL;

				// Resource & privilege is in string with delimiter
			} else {
				throw new Exceptions\InvalidArgumentException(sprintf('Permission must be only string with delimiter, array with resource & privilege or instance of IPub\Permissions\Entities\IPermission, %s given', gettype($permission)));
			}

			$privilege = $privilege === '' ? NS\IAuthorizator::ALL : $privilege;

			// Assign permission to service
			$permissionsProvider->addSetup('addPermission', [$resource, $privilege, $details]);
			$resourcesProvider->addSetup('addResource', [$resource->getResourceId()]);
		}
	}
}
