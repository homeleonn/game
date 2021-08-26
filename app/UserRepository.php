<?php

namespace App;

use DB;

class UserRepository
{
	private $store;
	private $app;
	public $users;
	public $usersFdsById;

	public function __construct($app)
	{
		$this->app 	 = $app;
		$this->store = $app->store;
	}

	public function add(int $fd, $user)
	{
		$this->users[$fd] 	 = new User($this->store, $fd, $user);
		$this->usersFdsById[$user->id] = $fd;

		return $this->users[$fd];
	}

	public function findByFd(int $fd)
	{
		if (isset($this->users[$fd])) {
			return $this->users[$fd];
		}

		$this->app->disconnectUndefinedUser($fd);
		// $this->app->removeFromApp($fd);
	}

	public function findById(int $id)
	{
		// По айди юзер может быть, но по айди соединения нет
		if (isset($this->usersFdsById[$id]) && isset($this->users[$this->usersFdsById[$id]])) {
			return $this->users[$this->usersFdsById[$id]];
		}
	}

	public function remove($user)
	{
		unset($this->usersFdsById[$user->getId()]);
		unset($this->users[$user->getFd()]);
	}

	public function init(int $fd, string $userId)
	{
		$user = $this->getUser($userId);

		$this->checkDuplicateConnection($userId);

		$user = $this->add($fd, $user);
		$this->app->locRepo->addUser($user);

		return $user;
	}

	private function getUser($userId)
	{
		return DB::getRow('SELECT id, login, level, curhp, maxhp, loc, trans_timeout FROM users WHERE id = ?i', $userId);
	}

	public function getAll()
	{
		return $this->users;
	}

	public function getAllByLoc($locId)
	{
		$users = [];
		
		foreach ($this->app->locRepo->getLoc($locId) as $fd => $dummy) {
			if ($this->has($fd)) {
				$users[] = $this->users[$fd]->show();
			}
		}

		return $users;
	}

	public function has($fd)
	{
		return isset($this->users[$fd]);
	}

	public function getIds()
	{
		return $this->usersFdsById;
	}

	public function sendLocUsers($user)
	{
		// users online by location
		$this->app->send($user->fd, ['loc_users' => array_values($this->getAllByLoc($user->getLoc()))]);
	}

	public function sendDataOfInitUser($user)
	{
		$this->app->send($user->getFd(), ['me' => (object)[
			'id' 				=> $user->id,
			'login' 			=> $user->login,
			'level' 			=> $user->level,
			'curhp' 			=> $user->curhp,
			'maxhp' 			=> $user->maxhp,
			'loc' 				=> $user->loc,
			'trans_timeout' 	=> $user->trans_timeout,
		]]);
	}


	public function checkDuplicateConnection($userId)
	{
		if (!$user = $this->findById($userId)) return;
		
		$this->disconnectPreviousDuplicateConnection($user->getFd());
		$this->remove($user);
		$this->app->removeFromApp(null, $user);
	}

	public function disconnectPreviousDuplicateConnection($fd)
	{
		$this->app->send($fd, Application::DUPLICATE);
		$this->app->server->close(null, $fd);
	}
}