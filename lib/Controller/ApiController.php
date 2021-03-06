<?php
/**
 * @copyright Copyright (c) 2017 Vinzenz Rosenkranz <vinzenz.rosenkranz@gmail.com>
 *
 * @author René Gieling <github@dartcafe.de>
 *
 * @license GNU AGPL version 3 or any later version
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as
 *  published by the Free Software Foundation, either version 3 of the
 *  License, or (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Polls\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Db\DoesNotExistException;

use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;

use OCA\Polls\Db\Event;
use OCA\Polls\Db\EventMapper;
use OCA\Polls\Db\Option;
use OCA\Polls\Db\OptionMapper;
use OCA\Polls\Db\Vote;
use OCA\Polls\Db\VoteMapper;
use OCA\Polls\Db\Comment;
use OCA\Polls\Db\CommentMapper;



class ApiController extends Controller {

	private $eventMapper;
	private $optionMapper;
	private $voteMapper;
	private $commentMapper;

	/**
	 * PageController constructor.
	 * @param string $appName
	 * @param IGroupManager $groupManager
	 * @param IRequest $request
	 * @param IUserManager $userManager
	 * @param string $userId
	 * @param EventMapper $eventMapper
	 * @param OptionMapper $optionMapper
	 * @param VoteMapper $VoteMapper
	 * @param CommentMapper $CommentMapper
	 */
	public function __construct(
		$appName,
		IGroupManager $groupManager,
		IRequest $request,
		IUserManager $userManager,
		$userId,
		EventMapper $eventMapper,
		OptionMapper $optionMapper,
		VoteMapper $VoteMapper,
		CommentMapper $CommentMapper
	) {
		parent::__construct($appName, $request);
		$this->userId = $userId;
		$this->groupManager = $groupManager;
		$this->userManager = $userManager;
		$this->eventMapper = $eventMapper;
		$this->optionMapper = $optionMapper;
		$this->voteMapper = $VoteMapper;
		$this->commentMapper = $CommentMapper;
	}

	/**
	 * Transforms a string with user and group names to an array
	 * of nextcloud users and groups
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @param string $item
	 * @return Array
	 */
	private function convertAccessList($item) {
		$split = array();
		if (strpos($item, 'user_') === 0) {
			$user = $this->userManager->get(substr($item, 5));
			$split = [
				'id' => $user->getUID(),
				'user' => $user->getUID(),
				'type' => 'user',
				'desc' => 'user',
				'icon' => 'icon-user',
				'displayName' => $user->getDisplayName(),
				'avatarURL' => '',
				'lastLogin' => $user->getLastLogin(),
				'cloudId' => $user->getCloudId()
			];
		} elseif (strpos($item, 'group_') === 0) {
			$group = substr($item, 6);
			$group = $this->groupManager->get($group);
			$split = [
				'id' => $group->getGID(),
				'user' => $group->getGID(),
				'type' => 'group',
				'desc' => 'group',
				'icon' => 'icon-group',
				'displayName' => $group->getDisplayName(),
				'avatarURL' => '',
			];
		}

		return($split);
	}

	/**
	 * Check if current user is in the access list
	 * @param Array $accessList
	 * @return Boolean
	 */
	private function checkUserAccess($accessList) {
		foreach ($accessList as $accessItem ) {
			if ($accessItem['type'] === 'user' && $accessItem['id'] === \OC::$server->getUserSession()->getUser()->getUID()) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check If current user is member of a group in the access list
	 * @param Array $accessList
	 * @return Boolean
	 */
	private function checkGroupAccess($accessList) {
		foreach ($accessList as $accessItem ) {
			if ($accessItem['type'] === 'group' && $this->groupManager->isInGroup(\OC::$server->getUserSession()->getUser()->getUID(),$accessItem['id'])) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Read all options of a poll based on the poll id
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @param Integer $pollId
	 * @return Array
	 */
	public function getOptions($pollId) {
		$optionList = array();
		$options = $this->optionMapper->findByPoll($pollId);
		foreach ($options as $optionElement) {
			$optionList[] = [
				'id' => $optionElement->getId(),
				'text' => htmlspecialchars_decode($optionElement->getPollOptionText()),
				'timestamp' => $optionElement->getTimestamp()
			];
		}

		return $optionList;
	}

	/**
	 * Read all votes of a poll based on the poll id
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @param Integer $pollId
	 * @return Array
	 */
	public function getVotes($pollId) {
		$votesList = array();
		$votes = $this->voteMapper->findByPoll($pollId);
		foreach ($votes as $voteElement) {
			$votesList[] = [
				'id' => $voteElement->getId(),
				'userId' => $voteElement->getUserId(),
				'voteOptionId' => $voteElement->getVoteOptionId(),
				'voteOptionText' => htmlspecialchars_decode($voteElement->getVoteOptionText()),
				'voteAnswer' => $voteElement->getVoteAnswer()
			];
		}

		return $votesList;
	}

	/**
	 * Read all comments of a poll based on the poll id
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @param Integer $pollId
	 * @return Array
	 */
	public function getComments($pollId) {
		$commentsList = array();
		$comments = $this->commentMapper->findByPoll($pollId);
		foreach ($comments as $commentElement) {
			$commentsList[] = [
				'id' => $commentElement->getId(),
				'userId' => $commentElement->getUserId(),
				'date' => $commentElement->getDt() . ' UTC',
				'comment' => $commentElement->getComment()
			];
		}

		return $commentsList;
	}

	/**
	 * Read an entire poll based on poll id
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @param Integer $pollId
	 * @return Array
	 */
	public function getEvent($pollId) {

		$data = array();

		try {
			$event = $this->eventMapper->find($pollId);

			if ($event->getType() == 0) {
				$pollType = 'datePoll';
			} else {
				$pollType = 'textPoll';
			}

			$accessType = $event->getAccess();
			if (!strpos('|public|hidden|registered', $accessType)) {
				$accessType = 'select';
			}

			if ($event->getExpire() === null) {
				$expired = false;
				$expiration = false;
			} else {
				$expired = time() > strtotime($event->getExpire());
				$expiration = true;
			}

			$data = [
				'id' => $event->getId(),
				'hash' => $event->getHash(),
				'type' => $pollType,
				'title' => $event->getTitle(),
				'description' => $event->getDescription(),
				'owner' => $event->getOwner(),
				'created' => $event->getCreated(),
				'access' => $accessType,
				'expiration' => $expiration,
				'expired' => $expired,
				'expirationDate' => $event->getExpire(),
				'isAnonymous' => $event->getIsAnonymous(),
				'fullAnonymous' => $event->getFullAnonymous(),
				'allowMaybe' => $event->getAllowMaybe()
			];

		} catch (DoesNotExistException $e) {
			// return silently
		} finally {
			return $data;
		}

	}

	/**
	 * Read all shares (users and groups with access) of a poll based on the poll id
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @param Integer $pollId
	 * @return Array
	 */
	public function getShares($pollId) {

		$accessList = array();

		try {
			$poll = $this->eventMapper->find($pollId);
			if (!strpos('|public|hidden|registered', $poll->getAccess())) {
				$accessList = explode(';', $poll->getAccess());
				$accessList = array_filter($accessList);
				$accessList = array_map(array($this, 'convertAccessList'), $accessList);
			}
		} catch (DoesNotExistException $e) {
			// return silently
		} finally {
			return $accessList;
		}

	}

	/**
	 * Set the access right of the current user for the poll
	 * @param Integer $pollId
	 * @return String
	 */
	private function grantAccessAs($pollId) {
		if (!\OC::$server->getUserSession()->getUser() instanceof IUser) {
			$currentUser = '';
		} else {
			$currentUser = \OC::$server->getUserSession()->getUser()->getUID();
		}

		$event = $this->getEvent($pollId);
		$accessList = $this->getShares($pollId);
		$grantAccessAs = 'none';

		if ($event['owner'] === $currentUser) {
			$grantAccessAs = 'owner';
		} elseif ($event['access'] === 'public') {
			$grantAccessAs = 'public';
		} elseif ($event['access'] === 'registered' && \OC::$server->getUserSession()->getUser() instanceof IUser) {
			$grantAccessAs = 'registered';
		} elseif ($this->checkUserAccess($accessList)) {
			$grantAccessAs = 'userInvitation';
		} elseif ($this->checkGroupAccess($accessList)) {
			$grantAccessAs = 'groupInvitation';
		} elseif ($this->groupManager->isAdmin($currentUser)) {
			$grantAccessAs = 'admin';
		}

		return $grantAccessAs;
	}


	/**
	 * Read an entire poll based on the poll id or hash
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @param String $pollIdOrHash poll id or hash
	 * @return Array
	 */
	public function getPoll($pollIdOrHash) {

		if (!\OC::$server->getUserSession()->getUser() instanceof IUser) {
			$currentUser = '';
		} else {
			$currentUser = \OC::$server->getUserSession()->getUser()->getUID();
		}

		$data = array();

		try {

			if (is_numeric($pollIdOrHash)) {
				$pollId = $this->eventMapper->find(intval($pollIdOrHash))->id;
				$result = 'foundById';
			} else {
				$pollId = $this->eventMapper->findByHash($pollIdOrHash)->id;
				$result = 'foundByHash';
			}

			$event = $this->getEvent($pollId);

			if ($event['owner'] !== $currentUser && !$this->groupManager->isAdmin($currentUser)) {
				$mode = 'create';
			} else {
				$mode = 'edit';
			}

			$data['poll'] = [
				'result' => $result,
				'grantedAs' => $this->grantAccessAs($event['id']),
				'mode' => $mode,
				'event' => $event,
				'comments' => $this->getComments($event['id']),
				'votes' => $this->getVotes($event['id']),
				'shares' => $this->getShares($event['id']),
				'options' => [
					'pollDates' => [],
					'pollTexts' => $this->getOptions($event['id'])
				]
			];
		} catch (DoesNotExistException $e) {
				$data['poll'] = ['result' => 'notFound'];
		} finally {
			return $data;
		}
	}

  	/**
	 * Get a list of NC users and groups
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @return DataResponse
	 */
	public function getSiteUsersAndGroups($query = '', $getGroups = true, $getUsers = true, $skipGroups = array(), $skipUsers = array()) {
		$list = array();
		$data = array();
		if ($getGroups) {
			$groups = $this->groupManager->search($query);
			foreach ($groups as $group) {
				if (!in_array($group->getGID(), $skipGroups)) {
					$list['g_' . $group->getGID()] = [
						'id' => $group->getGID(),
						'user' => $group->getGID(),
						'type' => 'group',
						'desc' => 'group',
						'icon' => 'icon-group',
						'displayName' => $group->getGID(),
						'avatarURL' => ''
					];
				}
			}
		}

		if ($getUsers) {
			$users = $this->userManager->searchDisplayName($query);
			foreach ($users as $user) {
				if (!in_array($user->getUID(), $skipUsers)) {
					$list['u_' . $user->getUID()] = [
						'id' => $user->getUID(),
						'user' => $user->getUID(),
						'type' => 'user',
						'desc' => 'user',
						'icon' => 'icon-user',
						'displayName' => $user->getDisplayName(),
						'avatarURL' => '',
						'lastLogin' => $user->getLastLogin(),
						'cloudId' => $user->getCloudId()
					];
				}
			}
		}

		$data['siteusers'] = $list;
		return new DataResponse($data, Http::STATUS_OK);
	}

	/**
	 * Get all polls
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * @return DataResponse
	 */

	public function getPolls() {
		if (!\OC::$server->getUserSession()->getUser() instanceof IUser) {
			return new DataResponse(null, Http::STATUS_UNAUTHORIZED);
		}

		try {
			$events = $this->eventMapper->findAll();
		} catch (DoesNotExistException $e) {
			return new DataResponse($e, Http::STATUS_NOT_FOUND);
		}

		$eventsList = array();

		foreach ($events as $eventElement) {
			$eventsList[$eventElement->id] = $this->getEvent($eventElement->id);
		}

		return new DataResponse($eventsList, Http::STATUS_OK);
	}

	/**
	 * Write poll (create/update)
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @param Array $event
	 * @param Array $options
	 * @param Array  $shares
	 * @param String $mode
	 * @return DataResponse
	 */
	public function writePoll($event, $options, $shares, $mode) {
		if (!\OC::$server->getUserSession()->getUser() instanceof IUser) {
			return new DataResponse(null, Http::STATUS_UNAUTHORIZED);
		} else {
			$currentUser = \OC::$server->getUserSession()->getUser()->getUID();
			$AdminAccess = $this->groupManager->isAdmin($currentUser);
		}

		$newEvent = new Event();

		// Set the configuration options entered by the user
		$newEvent->setTitle($event['title']);
		$newEvent->setDescription($event['description']);

		$newEvent->setType($event['type']);
		$newEvent->setIsAnonymous($event['isAnonymous']);
		$newEvent->setFullAnonymous($event['fullAnonymous']);
		$newEvent->setAllowMaybe($event['allowMaybe']);

		if ($event['access'] === 'select') {
			$shareAccess = '';
			foreach ($shares as $shareElement) {
				if ($shareElement['type'] === 'user') {
					$shareAccess = $shareAccess . 'user_' . $shareElement['id'] . ';';
				} elseif ($shareElement['type'] === 'group') {
					$shareAccess = $shareAccess . 'group_' . $shareElement['id'] . ';';
				}
			}
			$newEvent->setAccess(rtrim($shareAccess, ';'));
		} else {
			$newEvent->setAccess($event['access']);
		}

		if ($event['expiration']) {
			$newEvent->setExpire($event['expirationDate']);
		} else {
			$newEvent->setExpire(null);
		}

		if ($event['type'] === 'datePoll') {
			$newEvent->setType(0);
		} elseif ($event['type'] === 'textPoll') {
			$newEvent->setType(1);
		}

		if ($mode === 'edit') {
			// Edit existing poll
			$oldPoll = $this->eventMapper->findByHash($event['hash']);

			// Check if current user is allowed to edit existing poll
			if ($oldPoll->getOwner() !== $currentUser && !$AdminAccess) {
				// If current user is not owner of existing poll deny access
				return new DataResponse(null, Http::STATUS_UNAUTHORIZED);
			}

			// else take owner, hash and id of existing poll
			$newEvent->setOwner($oldPoll->getOwner());
			$newEvent->setHash($oldPoll->getHash());
			$newEvent->setId($oldPoll->getId());
			$this->eventMapper->update($newEvent);
			$this->optionMapper->deleteByPoll($newEvent->getId());

		} elseif ($mode === 'create') {
			// Create new poll
			// Define current user as owner, set new creation date and create a new hash
			$newEvent->setOwner($currentUser);
			$newEvent->setCreated(date('Y-m-d H:i:s'));
			$newEvent->setHash(\OC::$server->getSecureRandom()->generate(
				16,
				ISecureRandom::CHAR_DIGITS .
				ISecureRandom::CHAR_LOWER .
				ISecureRandom::CHAR_UPPER
			));
			$newEvent = $this->eventMapper->insert($newEvent);
		}

		// Update options
		if ($event['type'] === 'datePoll') {
			foreach ($options['pollDates'] as $optionElement) {
				$newOption = new Option();

				$newOption->setPollId($newEvent->getId());
				$newOption->setPollOptionText(date('Y-m-d H:i:s', $optionElement['timestamp']));
				$newOption->setTimestamp($optionElement['timestamp']);

				$this->optionMapper->insert($newOption);
			}
		} elseif ($event['type'] === "textPoll") {
			foreach ($options['pollTexts'] as $optionElement) {
				$newOption = new Option();

				$newOption->setPollId($newEvent->getId());
				$newOption->setpollOptionText(trim(htmlspecialchars($optionElement['text'])));

				$this->optionMapper->insert($newOption);
			}
		}

		return new DataResponse(array(
			'id' => $newEvent->getId(),
			'hash' => $newEvent->getHash()
		), Http::STATUS_OK);

	}
}
