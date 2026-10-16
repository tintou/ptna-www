<?php

namespace App\Controller;

use App\Network\NetworkDetailsReader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ConfigController extends AbstractController
{
    #[Route('/config', name: 'app_config')]
    public function index(Request $request, NetworkDetailsReader $reader): Response
    {
        $network = self::networkFromQuery($request);
        $details = $network !== '' ? $reader->read($network) : null;
        $found = $details !== null && $details->found();

        return $this->render('network/config.html.twig', [
            'network' => $found ? $network : '',
            'details' => $found ? $details : null,
            'options' => $found ? $reader->options($details) : [],
        ]);
    }

    // Old URLs /<lang>/config.php?network=...
    #[Route('/{lang}/config.php', name: 'app_config_legacy', requirements: ['lang' => 'bg|ca|cs|da|de|en|es|et|fa|fr|hr|id|it|nl|no|pl|pt|ro|ru|sl|sr|sv|fa_IR|fr_FR|nl_NL|pl_PL|pt_BR|ru_RU'])]
    public function legacy(Request $request, string $lang): RedirectResponse
    {
        return $this->redirectToRoute('app_config', ['lang' => substr($lang, 0, 2)] + $request->query->all(), 301);
    }

    /**
     * The network name of the request: 'network', or 'feed' with an optional 'release_date' for compatibility with the GTFS pages.
     */
    public static function networkFromQuery(Request $request): string
    {
        $feed = $request->query->get('feed');
        if (is_string($feed) && $feed !== '') {
            $release = $request->query->get('release_date');

            return is_string($release) && $release !== '' ? $feed . '-' . $release : $feed;
        }
        $network = $request->query->get('network');

        return is_string($network) ? $network : '';
    }
}
