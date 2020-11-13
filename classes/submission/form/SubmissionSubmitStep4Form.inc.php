<?php

/**
 * @file classes/submission/form/SubmissionSubmitStep4Form.inc.php
 *
 * Copyright (c) 2014-2019 Simon Fraser University
 * Copyright (c) 2003-2019 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class SubmissionSubmitStep4Form
 * @ingroup submission_form
 *
 * @brief Form for Step 4 of author submission.
 */

import('lib.pkp.classes.submission.form.PKPSubmissionSubmitStep4Form');

class SubmissionSubmitStep4Form extends PKPSubmissionSubmitStep4Form {
	/**
	 * Constructor.
	 * @param $context Context
	 * @param $submission Submission
	 */
	function __construct($context, $submission) {
		parent::__construct(
			$context,
			$submission
		);
	}

	/**
	 * Save changes to submission.
	 * @return int the submission ID
	 */
	function execute(...$functionArgs) {
		parent::execute();

		$submission = $this->submission;
		// Send author notification email
		import('classes.mail.ArticleMailTemplate');
		$mail = new ArticleMailTemplate($submission, 'SUBMISSION_ACK', null, null, false);
		$authorMail = new ArticleMailTemplate($submission, 'SUBMISSION_ACK_NOT_USER', null, null, false);

		$request = Application::getRequest();
		$context = $request->getContext();
		$router = $request->getRouter();
		if ($mail->isEnabled()) {
			// submission ack emails should be from the contact.
			$mail->setFrom($this->context->getSetting('contactEmail'), $this->context->getSetting('contactName'));
			$authorMail->setFrom($this->context->getSetting('contactEmail'), $this->context->getSetting('contactName'));

			$user = $request->getUser();
			$primaryAuthor = $submission->getPrimaryAuthor();
			if (!isset($primaryAuthor)) {
				$authors = $submission->getAuthors();
				$primaryAuthor = $authors[0];
			}
			$mail->addRecipient($user->getEmail(), $user->getFullName());
			// Add primary contact and e-mail address as specified in the journal submission settings
			if ($context->getSetting('copySubmissionAckPrimaryContact')) {
				$mail->addBcc(
					$context->getSetting('contactEmail'),
					$context->getSetting('contactName')
				);
			}
			if ($copyAddress = $context->getSetting('copySubmissionAckAddress')) {
				$mail->addBcc($copyAddress);
			}

			if ($user->getEmail() != $primaryAuthor->getEmail()) {
				$authorMail->addRecipient($primaryAuthor->getEmail(), $primaryAuthor->getFullName());
			}

			$assignedAuthors = $submission->getAuthors();

			foreach ($assignedAuthors as $author) {
				$authorEmail = $author->getEmail();
				// only add the author email if they have not already been added as the primary author
				// or user creating the submission.
				if ($authorEmail != $primaryAuthor->getEmail() && $authorEmail != $user->getEmail()) {
					$authorMail->addRecipient($author->getEmail(), $author->getFullName());
				}
			}
			$mail->bccAssignedSubEditors($submission->getId(), WORKFLOW_STAGE_ID_SUBMISSION);
			
			//prepare submission checklist array and copyright notice for mail template
			$submissionChecklist = $submission->getLocalizedData('accepted_submissionChecklist', $context->getPrimaryLocale());
			$submissionChecklistHTML = '<p><ul>';
			$order = array_column($submissionChecklist, 'order');
			$content = array_column($submissionChecklist, 'content');
			array_multisort($order,$content);
			foreach ($content as $value) {
			    $submissionChecklistHTML .= '<li>'.$value.'</li>';
			}
			$submissionChecklistHTML .= '</ul></p>';
			$copyrightNotice = '';
			if ($this->submission->getData('accepted_copyrightNotice')) {
			    $copyrightNotice = $submission->getLocalizedData('accepted_copyrightNotice', $context->getPrimaryLocale());
			}
			$privacyStatement = $submission->getLocalizedData('accepted_privacyStatement', $context->getPrimaryLocale());
			
			//check wehther submission is performed in LoginAS-scope
			$session = Application::getRequest()->getSession();
			$signedInAs = $session->getSessionVar('signedInAs');
			$submittingUser = '';
			if (isset($signedInAs) && !empty($signedInAs)) {
			    $signedInAs = (int)$signedInAs;
			    
			    $userDao = DAORegistry::getDAO('UserDAO');
			    $submittingUser = $userDao->getUserFullName($signedInAs);
			}

			$mail->assignParams(array(
				'authorName' => $user->getFullName(),
				'authorUsername' => $user->getUsername(),
				'editorialContactSignature' => $context->getSetting('contactName'),
				'submissionUrl' => $router->url($request, null, 'authorDashboard', 'submission', $submission->getId()),
			    //TODO RS see also mail template locale\en_US
			    'acceptedSubmissionChecklist' => $submissionChecklistHTML,
			    'acceptedCopyrightNotice' => '<br /><u>Copyright Notice</u><br />'.$copyrightNotice,
			    'acceptedPrivacyStatement' => $privacyStatement,
			    'submittingUser' => $submittingUser
			));
			
			$authorMail->assignParams(array(
				'submitterName' => $user->getFullName(),
				'editorialContactSignature' => $context->getSetting('contactName'),
			));

			if (!$mail->send($request)) {
				import('classes.notification.NotificationManager');
				$notificationMgr = new NotificationManager();
				$notificationMgr->createTrivialNotification($request->getUser()->getId(), NOTIFICATION_TYPE_ERROR, array('contents' => __('email.compose.error')));
			}

			$recipients = $authorMail->getRecipients();
			if (!empty($recipients)) {
				if (!$authorMail->send($request)) {
					import('classes.notification.NotificationManager');
					$notificationMgr = new NotificationManager();
					$notificationMgr->createTrivialNotification($request->getUser()->getId(), NOTIFICATION_TYPE_ERROR, array('contents' => __('email.compose.error')));
				}
			}
		}

		// Log submission.
		import('classes.log.SubmissionEventLogEntry'); // Constants
		import('lib.pkp.classes.log.SubmissionLog');
		SubmissionLog::logEvent($request, $submission, SUBMISSION_LOG_SUBMISSION_SUBMIT, 'submission.event.submissionSubmitted');
		SubmissionLog::logEvent($request, $submission, SUBMISSION_LOG_CHECKLIST_ACCEPTED, 'submission.event.submissionChecklistAccepted', array('submissionChecklist' => $submissionChecklistHTML));
		if ($this->submission->getData('accepted_copyrightNotice')) {
		    SubmissionLog::logEvent($request, $submission, SUBMISSION_LOG_COPYRIGHT_ACCEPTED, 'submission.event.submissionCopyrightAccepted', array('copyrightNotice' => $copyrightNotice));
		}
		SubmissionLog::logEvent($request, $submission, SUBMISSION_LOG_PRIVACY_ACCEPTED, 'submission.event.submissionPrivacyAccepted', array('privacyStatement' => $privacyStatement));
		
		return $this->submissionId;
	}
}


