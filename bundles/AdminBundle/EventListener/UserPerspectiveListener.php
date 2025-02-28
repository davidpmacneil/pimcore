<?php

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Bundle\AdminBundle\EventListener;

use Pimcore\Bundle\AdminBundle\Security\User\TokenStorageUserResolver;
use Pimcore\Bundle\CoreBundle\EventListener\Traits\PimcoreContextAwareTrait;
use Pimcore\Http\Request\Resolver\PimcoreContextResolver;
use Pimcore\Model\User;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * @internal
 */
class UserPerspectiveListener implements EventSubscriberInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;
    use PimcoreContextAwareTrait;

    /**
     * @var TokenStorageUserResolver
     */
    protected $userResolver;

    /**
     * @param TokenStorageUserResolver $userResolver
     */
    public function __construct(TokenStorageUserResolver $userResolver)
    {
        $this->userResolver = $userResolver;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
        ];
    }

    public function onKernelRequest(RequestEvent $event)
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest()) {
            return;
        }

        if (!$this->matchesPimcoreContext($request, PimcoreContextResolver::CONTEXT_ADMIN)) {
            return;
        }

        if ($user = $this->userResolver->getUser()) {
            $this->setRequestedPerspective($user, $request);
        }
    }

    /**
     * @param User $user
     * @param Request $request
     */
    protected function setRequestedPerspective(User $user, Request $request)
    {
        // update perspective settings
        $requestedPerspective = $request->get('perspective');

        if ($requestedPerspective) {
            if ($requestedPerspective !== $user->getActivePerspective()) {
                $existingPerspectives = array_keys(\Pimcore\Perspective\Config::get());
                if (!in_array($requestedPerspective, $existingPerspectives)) {
                    $this->logger->warning('Requested perspective {perspective} for {user} does not exist.', [
                        'user' => $user->getName(),
                        'perspective' => $requestedPerspective,
                    ]);

                    $requestedPerspective = null;
                }
            }
        }

        if (!$requestedPerspective || !$user->isAllowed($requestedPerspective, 'perspective')) {
            $previouslyRequested = $requestedPerspective;

            // choose active perspective or a first allowed
            $requestedPerspective = $user->isAllowed($user->getActivePerspective(), 'perspective')
                ? $user->getActivePerspective()
                : $user->getFirstAllowedPerspective();

            if ($previouslyRequested) {
                $this->logger->warning('User {user} is not allowed requested perspective {requestedPerspective}. Falling back to {perspective}.', [
                    'user' => $user->getName(),
                    'requestedPerspective' => $previouslyRequested,
                    'perspective' => $requestedPerspective,
                ]);
            } else {
                $this->logger->debug('Perspective for user {user} was not requested. Falling back to {perspective}.', [
                    'user' => $user->getName(),
                    'perspective' => $requestedPerspective,
                ]);
            }
        }

        if ($requestedPerspective !== $user->getActivePerspective()) {
            $this->logger->info('Setting active perspective for user {user} to {perspective}.', [
                'user' => $user->getName(),
                'perspective' => $requestedPerspective,
            ]);

            $user->setActivePerspective($requestedPerspective);
            $user->save();
        }
    }
}
