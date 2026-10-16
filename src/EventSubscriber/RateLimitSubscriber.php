<?php

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Keeps scrapers away from the pages that open the GTFS databases (what script/check-request.php did).
 *
 * Without a Referer header only a few requests per client are served. With a Referer, only the
 * pages that read a release of a feed are limited. Unlike the legacy script, which shared one
 * counter between all visitors, every client IP has its own limit.
 */
final class RateLimitSubscriber implements EventSubscriberInterface
{
    private const RETRY_AFTER = 20;

    private const CHECKED_ROUTES = [
        'app_api_gtfs',
        'app_config',
        'app_gtfs_details',
        'app_gtfs_analysis_details',
        'app_gtfs_statistics',
        'app_showlogs',
        'app_statistics',
        'app_testcsv',
        'app_queue',
    ];

    private const RELEASE_ROUTES = [
        'app_gtfs_compare_feeds',
        'app_gtfs_compare_versions',
        'app_gtfs_compare_routes',
        'app_gtfs_compare_trips',
        'app_gtfs_compare_shapes',
        'app_gtfs_routes',
        'app_gtfs_trips',
        'app_gtfs_single_trip',
        'app_gtfs_shape',
    ];

    public function __construct(
        #[Autowire(service: 'limiter.ptna_no_referrer')]
        private readonly RateLimiterFactory $noReferrerLimiter,
        #[Autowire(service: 'limiter.ptna_release')]
        private readonly RateLimiterFactory $releaseLimiter,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // after the router (32) has matched the route
        return [KernelEvents::REQUEST => ['onKernelRequest', 8]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $route = $request->attributes->get('_route');
        if (!$event->isMainRequest() || !is_string($route)) {
            return;
        }

        $isRelease = in_array($route, self::RELEASE_ROUTES, true);
        if (!$isRelease && !in_array($route, self::CHECKED_ROUTES, true)) {
            return;
        }

        $client = $request->getClientIp() ?? 'unknown';

        if (!$request->headers->get('Referer')) {
            $limit = $this->noReferrerLimiter->create($client)->consume();
        } elseif ($isRelease && $this->asksForRelease($request)) {
            $limit = $this->releaseLimiter->create($client . '|' . $route)->consume();
        } else {
            return;
        }

        if (!$limit->isAccepted()) {
            $event->setResponse(new Response('Too many requests', Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => (string) self::RETRY_AFTER]));
        }
    }

    private function asksForRelease(Request $request): bool
    {
        $release = $request->query->get('release_date');

        return is_string($release) && preg_match('/^(latest|previous|long-term|20)/', $release) === 1;
    }
}
