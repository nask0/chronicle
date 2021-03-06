<?php
declare(strict_types=1);
namespace ParagonIE\Chronicle\Middleware;

use ParagonIE\Chronicle\Chronicle;
use ParagonIE\Chronicle\Exception\ClientNotFound;
use ParagonIE\Chronicle\MiddlewareInterface;
use ParagonIE\ConstantTime\Base64UrlSafe;
use ParagonIE\Sapient\CryptographyKeys\SigningPublicKey;
use Psr\Http\Message\{
    RequestInterface,
    ResponseInterface
};
use Slim\Http\Request;

/**
 * Class CheckClientSignature
 *
 * Checks the client signature on a RequestInterface
 *
 * @package ParagonIE\Chronicle\Middleware
 */
class CheckClientSignature implements MiddlewareInterface
{
    /**
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @param callable $next
     * @return ResponseInterface
     */
    public function __invoke(
        RequestInterface $request,
        ResponseInterface $response,
        callable $next
    ): ResponseInterface {
        $header = $request->getHeader(Chronicle::CLIENT_IDENTIFIER_HEADER);
        if (!$header) {
            return Chronicle::errorResponse($response, 'No client header provided', 403);
        }
        foreach ($header as $clientId) {
            try {
                $publicKey = $this->getPublicKey($clientId);
            } catch (ClientNotFound $ex) {
                continue;
            }
        }
        if (!isset($publicKey)) {
            return Chronicle::errorResponse($response, 'Invalid client', 403);
        }
        try {
            $request = Chronicle::getSapient()->verifySignedRequest($request, $publicKey);
            if ($request instanceof Request) {
                // Cache authenticated status
                $request = $request->withAttribute('authenticated', true);
            }
        } catch (\Throwable $ex) {
            return Chronicle::errorResponse($response, $ex->getMessage(), 403);
        }

        return $next($request, $response);
    }

    /**
     * @param string $clientId
     * @return SigningPublicKey
     * @throws ClientNotFound
     */
    public function getPublicKey(string $clientId): SigningPublicKey
    {
        $sqlResult = Chronicle::getDatabase()->row(
            "SELECT * FROM chronicle_clients WHERE publicid = ?",
            $clientId
        );
        if (empty($sqlResult)) {
            throw new ClientNotFound('Client not found');
        }
        return new SigningPublicKey(
            Base64UrlSafe::decode($sqlResult['publickey'])
        );
    }
}
