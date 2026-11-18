<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Customer;
use App\Entity\Ticket;
use App\Repository\TicketRepository;
use App\Service\CustomerAuth;
use App\Service\QrCodeGenerator;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class QrMenuExtension extends AbstractExtension
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly TicketRepository $ticketRepository,
        private readonly CustomerAuth $customerAuth,
        private readonly QrCodeGenerator $qrCodeGenerator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('current_qr_ticket', $this->getCurrentQrTicket(...)),
            new TwigFunction('current_customer', $this->getCurrentCustomer(...)),
            new TwigFunction('render_qr_svg', $this->renderQrSvg(...), ['is_safe' => ['html']]),
        ];
    }

    public function getCurrentCustomer(): ?Customer
    {
        return $this->customerAuth->getCurrentCustomer();
    }

    public function renderQrSvg(string $token, int $size = 136): string
    {
        $request = $this->requestStack->getCurrentRequest();
        $baseUrl = $request !== null ? $request->getSchemeAndHttpHost() : 'http://localhost:8080';

        return $this->qrCodeGenerator->generateSvg($baseUrl . '/festival/door/' . $token, $size);
    }

    public function getCurrentQrTicket(): ?Ticket
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return null;
        }

        $token = null;
        if ($request->hasPreviousSession()) {
            $sessionToken = $request->getSession()->get('latest_qr_token');
            if (is_string($sessionToken) && $sessionToken !== '') {
                $token = $sessionToken;
            }
        }

        if ($token === null) {
            $cookieToken = $request->cookies->get('latest_qr_token');
            if (is_string($cookieToken) && $cookieToken !== '') {
                $token = $cookieToken;
            }
        }

        if ($token !== null) {
            $ticket = $this->ticketRepository->findOneByQrToken($token);
            if ($ticket !== null && $ticket->isFestivalTicket()) {
                return $ticket;
            }
        }

        $customer = $this->customerAuth->getCurrentCustomer();
        if ($customer !== null) {
            $customerTickets = $this->ticketRepository->findForCustomer($customer);
            foreach ($customerTickets as $t) {
                if ($t->getQrToken() !== null) {
                    return $t;
                }
            }
        }

        return null;
    }
}
