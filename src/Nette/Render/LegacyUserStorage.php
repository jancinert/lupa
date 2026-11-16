<?php declare(strict_types=1);

namespace Lupa\Nette\Render;

use Nette\Security\IIdentity;
use Nette\Security\IUserStorage;


/**
 * Keeps the login of a rendered request in memory for nette/security before 3.1, where the storage is IUserStorage.
 * The parameters carry no types, because the interface got them only in nette/security 3.0.
 */
final class LegacyUserStorage implements IUserStorage
{
	private bool $authenticated = false;
	private ?IIdentity $identity = null;


	/**
	 * @param bool $state
	 * @return static
	 */
	public function setAuthenticated($state)
	{
		$this->authenticated = (bool) $state;
		return $this;
	}


	public function isAuthenticated(): bool
	{
		return $this->authenticated;
	}


	/**
	 * @param ?IIdentity $identity
	 * @return static
	 */
	public function setIdentity($identity = null)
	{
		$this->identity = $identity;
		return $this;
	}


	public function getIdentity(): ?IIdentity
	{
		return $this->identity;
	}


	/**
	 * @param mixed $time
	 * @param int $flags
	 * @return static
	 */
	public function setExpiration($time, $flags = 0)
	{
		return $this;
	}


	public function getLogoutReason(): ?int
	{
		return null;
	}
}
