<?php

/**
 * @file pages/previewButton/PreviewButtonHandler.inc.php
 *
 * @class PreviewButtonHandler
 * @brief Render an existing article preview for users with workflow access.
 */

import('pages.article.ArticleHandler');

class PreviewButtonHandler extends ArticleHandler {
	/**
	 * @copydoc ArticleHandler::initialize()
	 */
	public function initialize($request, $args = array()) {
		$submissionId = !empty($args) ? array_shift($args) : null;
		$submission = $submissionId ? Services::get('submission')->get((int) $submissionId) : null;
		$context = $request->getContext();

		if (!$submission || !$context || $submission->getData('contextId') !== $context->getId()) {
			$request->getDispatcher()->handle404();
		}

		if (!$this->canPreviewSubmission($request, $submission)) {
			$request->getDispatcher()->handle404();
		}

		$this->article = $submission;
		$this->galley = null;
		$this->fileId = null;
		$this->issue = null;

		$subPath = empty($args) ? 0 : array_shift($args);
		if ($subPath === 'version') {
			$publicationId = (int) array_shift($args);
			$galleyId = empty($args) ? 0 : array_shift($args);
			foreach ((array) $this->article->getData('publications') as $publication) {
				if ($publication->getId() === $publicationId) {
					$this->publication = $publication;
				}
			}
			if (!$this->publication) {
				$request->getDispatcher()->handle404();
			}
		} else {
			$this->publication = $this->article->getCurrentPublication();
			$galleyId = $subPath;
		}

		if ($galleyId && in_array($request->getRequestedOp(), ['view', 'download'])) {
			foreach ((array) $this->publication->getData('galleys') as $galley) {
				if ($galley->getBestGalleyId() == $galleyId) {
					$this->galley = $galley;
					break;
				}
			}
			if (!$this->galley) {
				$request->getDispatcher()->handle404();
			}

			if (!empty($args)) {
				$this->fileId = array_shift($args);
			}
		}

		if ($this->publication && $this->publication->getData('issueId')) {
			$issueDao = DAORegistry::getDAO('IssueDAO'); /* @var $issueDao IssueDAO */
			$this->issue = $issueDao->getById($this->publication->getData('issueId'), $submission->getData('contextId'), true);
		}
	}

	/**
	 * Check whether the current user has OJS workflow access to this submission.
	 *
	 * @param Request $request
	 * @param Submission $submission
	 * @return bool
	 */
	protected function canPreviewSubmission($request, $submission) {
		$user = $request->getUser();
		$context = $request->getContext();

		if (!$user || !$context) {
			return false;
		}

		$accessibleWorkflowStages = Services::get('user')->getAccessibleWorkflowStages(
			$user->getId(),
			$context->getId(),
			$submission,
			$this->getUserRoleIds($user, $context)
		);

		return !empty($accessibleWorkflowStages);
	}

	/**
	 * Get the current user's role ids without triggering OJS 3.3 site-admin notices.
	 *
	 * @param User $user
	 * @param Context $context
	 * @return array
	 */
	protected function getUserRoleIds($user, $context) {
		$roleDao = DAORegistry::getDAO('RoleDAO'); /* @var $roleDao RoleDAO */
		$userRoles = $roleDao->getByUserIdGroupedByContext($user->getId());
		$userRoleIds = [];

		if (array_key_exists($context->getId(), $userRoles)) {
			foreach ($userRoles[$context->getId()] as $contextRole) {
				$userRoleIds[] = $contextRole->getRoleId();
			}
		}

		if (array_key_exists(CONTEXT_ID_NONE, $userRoles)) {
			foreach ($userRoles[CONTEXT_ID_NONE] as $siteRole) {
				if ($siteRole->getRoleId() == ROLE_ID_SITE_ADMIN) {
					$userRoleIds[] = ROLE_ID_SITE_ADMIN;
					break;
				}
			}
		}

		return array_values(array_unique($userRoleIds));
	}

	/**
	 * @copydoc ArticleHandler::userCanViewGalley()
	 */
	public function userCanViewGalley($request, $articleId, $galleyId = null) {
		if (isset($this->article) && $this->canPreviewSubmission($request, $this->article)) {
			return true;
		}

		return parent::userCanViewGalley($request, $articleId, $galleyId);
	}
}
