<?php

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class LocaleSubscriber implements EventSubscriberInterface
{
    /** @param string[] $enabledLocales */
    public function __construct(
        #[Autowire(param: 'kernel.enabled_locales')]
        private readonly array $enabledLocales,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Must run before Symfony's own LocaleListener (priority 16)
        return [KernelEvents::REQUEST => ['onKernelRequest', 20]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $lang = $request->query->get('lang');

        if (\is_string($lang) && \in_array($lang, $this->enabledLocales, true)) {
            $request->setLocale($lang);

            return;
        }

        // Without an explicit ?lang= use the first language of the browser that is available
        foreach ($request->getLanguages() as $browserLang) {
            $browserLang = strtolower(substr($browserLang, 0, 2));
            if (\in_array($browserLang, $this->enabledLocales, true)) {
                $request->setLocale($browserLang);

                return;
            }
        }
    }
}
