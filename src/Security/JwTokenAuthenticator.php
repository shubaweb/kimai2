<?php
declare(strict_types=1);

namespace App\Security;

use Lcobucci\JWT\Parser;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\ValidationData;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Guard\AbstractGuardAuthenticator;

/**
 * @name \App\Security\JwTokenAuthenticator
 * @author     Sergey Kashuba <sk@networkofarts.com>
 * @copyright  Network of Arts AG
 *
 */
class JwTokenAuthenticator extends AbstractGuardAuthenticator
{

    /**
     * @var string the default cookie name containing the jwt token
     */
    public const JWT_COOKIE_NAME = 'jwt';
    /**
     * @var string the claim that contains the roles
     */
    public const CLAIM_ROLES = 'rls';
    /**
     * @var string the claim that contains user login in kimai
     */
    public const JWT_CLAIM_USER_MAIL = 'uml';
    /**
     * @var string key of application host
     */
    public const APP_HOST = 'APP_HOST';
    /**
     * @var string public key for decoding
     */
    public const JWT_PUBLIC_KEY = <<<KEY
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAnzyis1ZjfNB0bBgKFMSv
vkTtwlvBsaJq7S5wA+kzeVOVpVWwkWdVha4s38XM/pa/yr47av7+z3VTmvDRyAHc
aT92whREFpLv9cj5lTeJSibyr/Mrm/YtjCZVWgaOYIhwrXwKLqPr/11inWsAkfIy
tvHWTxZYEcXLgAXFuUuaS3uF9gEiNQwzGTU1v0FqkqTBr4B8nW3HCN47XUu0t8Y0
e+lf4s4OxQawWD79J9/5d3Ry0vbV3Am1FtGJiJvOwRsIfVChDpYStTcHTCMqtvWb
V6L11BWkpzGXSW4Hv43qa+GSYOD2QU68Mb59oSk2OB+BtOLpJofmbGEGgvmwyCI9
MwIDAQAB
-----END PUBLIC KEY-----
KEY;
    /**
     * @var string role for kimai user
     */
    public const ROLE_APP_KIMAI = 'ROLE_APP_KIMAI';

    /**
     * @param Request $request
     * @return bool
     */
    public function supports(Request $request)
    {
        if (strpos($request->getRequestUri(), '/api/doc') === 0) {
            return false;
        }

        if (strpos($request->getRequestUri(), '/api/') === 0) {
            return $request->cookies->has(self::JWT_COOKIE_NAME);
        }

        return true;
    }

    /**
     * @param Request $request
     * @return array
     */
    public function getCredentials(Request $request)
    {
        return [
            'token' => $request->cookies->get(self::JWT_COOKIE_NAME),
        ];
    }

    /**
     * @param array                 $credentials
     * @param UserProviderInterface $userProvider
     * @return null|UserInterface
     */
    public function getUser($credentials, UserProviderInterface $userProvider)
    {
        $jwtToken = (new Parser())->parse($credentials['token']);
        if (!$jwtToken->verify(new Sha256(), self::JWT_PUBLIC_KEY)) {
            throw new AuthenticationException('Invalid token signature');
        }

        $data = new ValidationData();
        $data->setIssuer($_ENV[self::APP_HOST]);
        $data->has(self::JWT_CLAIM_USER_MAIL);

        if (!$jwtToken->validate($data)) {
            throw new AuthenticationException('Invalid token');
        }

        $user = $jwtToken->getClaim(self::JWT_CLAIM_USER_MAIL);

        return $userProvider->loadUserByUsername($user);
    }

    /**
     * @param array         $credentials
     * @param UserInterface $user
     * @return bool
     */
    public function checkCredentials($credentials, UserInterface $user)
    {
        $jwtToken = (new Parser())->parse($credentials['token']);

        return in_array(self::ROLE_APP_KIMAI,
            $jwtToken->getClaim(self::CLAIM_ROLES));
    }

    /**
     * @param Request        $request
     * @param TokenInterface $token
     * @param string         $providerKey
     * @return null|Response
     */
    public function onAuthenticationSuccess(
        Request $request,
        TokenInterface $token,
        $providerKey
    ) {
        return null;
    }

    /**
     * @param Request                 $request
     * @param AuthenticationException $exception
     * @return null|JsonResponse|Response
     */
    public function onAuthenticationFailure(
        Request $request,
        AuthenticationException $exception
    ) {
        if (!$request->cookies->has(self::JWT_COOKIE_NAME)) {
            return new JsonResponse(
                ['message' => 'Authentication required, missing Jwt token'],
                Response::HTTP_FORBIDDEN
            );
        }
        $data = [
            'message' => 'Invalid token'
            // security measure: do not leak real reason (unknown user, invalid credentials ...)
            // you can uncomment this for debugging
            // 'message' => strtr($exception->getMessageKey(), $exception->getMessageData())
        ];

        return new JsonResponse($data, Response::HTTP_FORBIDDEN);
    }

    /**
     * @param Request                      $request
     * @param AuthenticationException|null $authException
     * @return JsonResponse|Response
     */
    public function start(
        Request $request,
        AuthenticationException $authException = null
    ) {
        $data = [
            'message' => 'Authentication required, missing Jwt token',
        ];

        return new JsonResponse($data, Response::HTTP_UNAUTHORIZED);
    }

    /**
     * @return bool
     */
    public function supportsRememberMe()
    {
        return false;
    }
}