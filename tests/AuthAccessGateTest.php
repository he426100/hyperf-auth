<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-ext/auth.
 *
 * @link     https://github.com/hyperf-ext/auth
 * @contact  eric@zhu.email
 * @license  https://github.com/hyperf-ext/auth/blob/master/LICENSE
 */
namespace HyperfTest;

use Hyperf\Context\ApplicationContext;
use HyperfExt\Auth\Access\Gate;
use HyperfExt\Auth\Access\HandlesAuthorization;
use HyperfExt\Auth\Access\Response;
use HyperfExt\Auth\Contracts\AuthenticatableInterface;
use HyperfExt\Auth\Exceptions\AuthorizationException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @internal
 * @coversNothing
 */
class AuthAccessGateTest extends TestCase
{
    public function getContainer()
    {
        return ApplicationContext::getContainer();
    }

    public function testBasicClosuresCanBeDefined()
    {
        $gate = $this->getBasicGate();

        $gate->define('foo', function ($user) {
            return true;
        });
        $gate->define('bar', function ($user) {
            return false;
        });

        $this->assertTrue($gate->check('foo'));
        $this->assertFalse($gate->check('bar'));
    }

    public function testBeforeCanTakeAnArrayCallbackAsObject()
    {
        $gate = new Gate($this->getContainer(), function () {
        });

        $gate->before([new AccessGateTestBeforeCallback(), 'allowEverything']);

        $this->assertTrue($gate->check('anything'));
    }

    public function testBeforeCanTakeAnArrayCallbackAsObjectStatic()
    {
        $gate = new Gate($this->getContainer(), function () {
        });

        $gate->before([new AccessGateTestBeforeCallback(), 'allowEverythingStatically']);

        $this->assertTrue($gate->check('anything'));
    }

    public function testBeforeCanTakeAnArrayCallbackWithStaticMethod()
    {
        $gate = new Gate($this->getContainer(), function () {
        });

        $gate->before([AccessGateTestBeforeCallback::class, 'allowEverythingStatically']);

        $this->assertTrue($gate->check('anything'));
    }

    public function testBeforeCanAllowGuests()
    {
        $gate = new Gate($this->getContainer(), function () {
        });

        $gate->before(function (?User $user) {
            return true;
        });

        $this->assertTrue($gate->check('anything'));
    }

    public function testAfterCanAllowGuests()
    {
        $gate = new Gate($this->getContainer(), function () {
        });

        $gate->after(function (?User $user) {
            return true;
        });

        $this->assertTrue($gate->check('anything'));
    }

    public function testClosuresCanAllowGuestUsers()
    {
        $gate = new Gate($this->getContainer(), function () {
        });

        $gate->define('foo', function (?User $user) {
            return true;
        });

        $gate->define('bar', function (User $user) {
            return false;
        });

        $this->assertTrue($gate->check('foo'));
        $this->assertFalse($gate->check('bar'));
    }

    public function testPoliciesCanAllowGuests()
    {
        unset($_SERVER['__hyperf.testBefore']);

        $gate = new Gate($this->getContainer(), function () {
        });

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicyThatAllowsGuests::class);

        $this->assertTrue($gate->check('edit', new AccessGateTestDummy()));
        $this->assertFalse($gate->check('update', new AccessGateTestDummy()));
        $this->assertTrue($_SERVER['__hyperf.testBefore']);

        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicyThatAllowsGuests::class);

        $this->assertTrue($gate->check('edit', new AccessGateTestDummy()));
        $this->assertTrue($gate->check('update', new AccessGateTestDummy()));

        unset($_SERVER['__hyperf.testBefore']);
    }

    public function testPolicyBeforeNotCalledWithGuestsIfItDoesntAllowThem()
    {
        $_SERVER['__hyperf.testBefore'] = false;

        $gate = new Gate($this->getContainer(), function () {
        });

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicyWithNonGuestBefore::class);

        $this->assertTrue($gate->check('edit', new AccessGateTestDummy()));
        $this->assertFalse($gate->check('update', new AccessGateTestDummy()));
        $this->assertFalse($_SERVER['__hyperf.testBefore']);

        unset($_SERVER['__hyperf.testBefore']);
    }

    public function testBeforeAndAfterCallbacksCanAllowGuests()
    {
        $_SERVER['__hyperf.gateBefore'] = false;
        $_SERVER['__hyperf.gateBefore2'] = false;
        $_SERVER['__hyperf.gateAfter'] = false;
        $_SERVER['__hyperf.gateAfter2'] = false;

        $gate = new Gate($this->getContainer(), function () {
        });

        $gate->before(function (?User $user) {
            $_SERVER['__hyperf.gateBefore'] = true;
        });

        $gate->after(function (?User $user) {
            $_SERVER['__hyperf.gateAfter'] = true;
        });

        $gate->before(function (User $user) {
            $_SERVER['__hyperf.gateBefore2'] = true;
        });

        $gate->after(function (User $user) {
            $_SERVER['__hyperf.gateAfter2'] = true;
        });

        $gate->define('foo', function ($user = null) {
            return true;
        });

        $this->assertTrue($gate->check('foo'));

        $this->assertTrue($_SERVER['__hyperf.gateBefore']);
        $this->assertFalse($_SERVER['__hyperf.gateBefore2']);
        $this->assertTrue($_SERVER['__hyperf.gateAfter']);
        $this->assertFalse($_SERVER['__hyperf.gateAfter2']);

        unset($_SERVER['__hyperf.gateBefore'], $_SERVER['__hyperf.gateBefore2'], $_SERVER['__hyperf.gateAfter'], $_SERVER['__hyperf.gateAfter2']);
    }

    public function testResourceGatesCanBeDefined()
    {
        $gate = $this->getBasicGate();

        $gate->resource('test', AccessGateTestResource::class);

        $dummy = new AccessGateTestDummy();

        $this->assertTrue($gate->check('test.view'));
        $this->assertTrue($gate->check('test.create'));
        $this->assertTrue($gate->check('test.update', $dummy));
        $this->assertTrue($gate->check('test.delete', $dummy));
    }

    public function testCustomResourceGatesCanBeDefined()
    {
        $gate = $this->getBasicGate();

        $abilities = [
            'ability1' => 'foo',
            'ability2' => 'bar',
        ];

        $gate->resource('test', AccessGateTestCustomResource::class, $abilities);

        $this->assertTrue($gate->check('test.ability1'));
        $this->assertTrue($gate->check('test.ability2'));
    }

    public function testBeforeCallbacksCanOverrideResultIfNecessary()
    {
        $gate = $this->getBasicGate();

        $gate->define('foo', function ($user) {
            return true;
        });
        $gate->before(function ($user, $ability) {
            $this->assertSame('foo', $ability);

            return false;
        });

        $this->assertFalse($gate->check('foo'));
    }

    public function testBeforeCallbacksDontInterruptGateCheckIfNoValueIsReturned()
    {
        $gate = $this->getBasicGate();

        $gate->define('foo', function ($user) {
            return true;
        });
        $gate->before(function () {
        });

        $this->assertTrue($gate->check('foo'));
    }

    public function testAfterCallbacksAreCalledWithResult()
    {
        $gate = $this->getBasicGate();

        $gate->define('foo', function ($user) {
            return true;
        });

        $gate->define('bar', function ($user) {
            return false;
        });

        $gate->after(function ($user, $ability, $result) {
            if ($ability == 'foo') {
                $this->assertTrue($result, 'After callback on `foo` should receive true as result');
            } elseif ($ability == 'bar') {
                $this->assertFalse($result, 'After callback on `bar` should receive false as result');
            } else {
                $this->assertNull($result, 'After callback on `missing` should receive null as result');
            }
        });

        $this->assertTrue($gate->check('foo'));
        $this->assertFalse($gate->check('bar'));
        $this->assertFalse($gate->check('missing'));
    }

    public function testAfterCallbacksCanAllowIfNull()
    {
        $gate = $this->getBasicGate();

        $gate->after(function ($user, $ability, $result) {
            return true;
        });

        $this->assertTrue($gate->allows('null'));
    }

    public function testAfterCallbacksDoNotOverridePreviousResult()
    {
        $gate = $this->getBasicGate();

        $gate->define('deny', function ($user) {
            return false;
        });

        $gate->define('allow', function ($user) {
            return true;
        });

        $gate->after(function ($user, $ability, $result) {
            return ! $result;
        });

        $this->assertTrue($gate->allows('allow'));
        $this->assertTrue($gate->denies('deny'));
    }

    public function testAfterCallbacksDoNotOverrideEachOther()
    {
        $gate = $this->getBasicGate();

        $gate->after(function ($user, $ability, $result) {
            return $ability == 'allow';
        });

        $gate->after(function ($user, $ability, $result) {
            return ! $result;
        });

        $this->assertTrue($gate->allows('allow'));
        $this->assertTrue($gate->denies('deny'));
    }

    public function testCurrentUserThatIsOnGateAlwaysInjectedIntoClosureCallbacks()
    {
        $gate = $this->getBasicGate();

        $gate->define('foo', function ($user) {
            $this->assertEquals(1, $user->id);

            return true;
        });

        $this->assertTrue($gate->check('foo'));
    }

    public function testASingleArgumentCanBePassedWhenCheckingAbilities()
    {
        $gate = $this->getBasicGate();

        $dummy = new AccessGateTestDummy();

        $gate->before(function ($user, $ability, array $arguments) use ($dummy) {
            $this->assertCount(1, $arguments);
            $this->assertSame($dummy, $arguments[0]);
        });

        $gate->define('foo', function ($user, $x) use ($dummy) {
            $this->assertSame($dummy, $x);

            return true;
        });

        $gate->after(function ($user, $ability, $result, array $arguments) use ($dummy) {
            $this->assertCount(1, $arguments);
            $this->assertSame($dummy, $arguments[0]);
        });

        $this->assertTrue($gate->check('foo', $dummy));
    }

    public function testMultipleArgumentsCanBePassedWhenCheckingAbilities()
    {
        $gate = $this->getBasicGate();

        $dummy1 = new AccessGateTestDummy();
        $dummy2 = new AccessGateTestDummy();

        $gate->before(function ($user, $ability, array $arguments) use ($dummy1, $dummy2) {
            $this->assertCount(2, $arguments);
            $this->assertSame([$dummy1, $dummy2], $arguments);
        });

        $gate->define('foo', function ($user, $x, $y) use ($dummy1, $dummy2) {
            $this->assertSame($dummy1, $x);
            $this->assertSame($dummy2, $y);

            return true;
        });

        $gate->after(function ($user, $ability, $result, array $arguments) use ($dummy1, $dummy2) {
            $this->assertCount(2, $arguments);
            $this->assertSame([$dummy1, $dummy2], $arguments);
        });

        $this->assertTrue($gate->check('foo', [$dummy1, $dummy2]));
    }

    public function testClassesCanBeDefinedAsCallbacksUsingAtNotation()
    {
        $gate = $this->getBasicGate();

        $gate->define('foo', AccessGateTestClass::class . '@foo');

        $this->assertTrue($gate->check('foo'));
    }

    public function testInvokableClassesCanBeDefined()
    {
        $gate = $this->getBasicGate();

        $gate->define('foo', AccessGateTestInvokableClass::class);

        $this->assertTrue($gate->check('foo'));
    }

    public function testGatesCanBeDefinedUsingAnArrayCallback()
    {
        $gate = $this->getBasicGate();

        $gate->define('foo', [new AccessGateTestStaticClass(), 'foo']);

        $this->assertTrue($gate->check('foo'));
    }

    public function testGatesCanBeDefinedUsingAnArrayCallbackWithStaticMethod()
    {
        $gate = $this->getBasicGate();

        $gate->define('foo', [AccessGateTestStaticClass::class, 'foo']);

        $this->assertTrue($gate->check('foo'));
    }

    public function testPolicyClassesCanBeDefinedToHandleChecksForGivenType()
    {
        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicy::class);

        $this->assertTrue($gate->check('update', new AccessGateTestDummy()));
    }

    public function testPolicyClassesHandleChecksForAllSubtypes()
    {
        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicy::class);

        $this->assertTrue($gate->check('update', new AccessGateTestSubDummy()));
    }

    public function testPolicyClassesHandleChecksForInterfaces()
    {
        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummyInterface::class, AccessGateTestPolicy::class);

        $this->assertTrue($gate->check('update', new AccessGateTestSubDummy()));
    }

    public function testPolicyConvertsDashToCamel()
    {
        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicy::class);

        $this->assertTrue($gate->check('update-dash', new AccessGateTestDummy()));
    }

    public function testPolicyDefaultToFalseIfMethodDoesNotExistAndGateDoesNotExist()
    {
        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicy::class);

        $this->assertFalse($gate->check('nonexistent_method', new AccessGateTestDummy()));
    }

    public function testPolicyClassesCanBeDefinedToHandleChecksForGivenClassName()
    {
        $gate = $this->getBasicGate(true);

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicy::class);

        $this->assertTrue($gate->check('create', [AccessGateTestDummy::class, true]));
    }

    public function testPoliciesMayHaveBeforeMethodsToOverrideChecks()
    {
        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicyWithBefore::class);

        $this->assertTrue($gate->check('update', new AccessGateTestDummy()));
    }

    public function testPoliciesAlwaysOverrideClosuresWithSameName()
    {
        $gate = $this->getBasicGate();

        $gate->define('update', function () {
            $this->fail();
        });

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicy::class);

        $this->assertTrue($gate->check('update', new AccessGateTestDummy()));
    }

    public function testPoliciesDeferToGatesIfMethodDoesNotExist()
    {
        $gate = $this->getBasicGate();

        $gate->define('nonexistent_method', function ($user) {
            return true;
        });

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicy::class);

        $this->assertTrue($gate->check('nonexistent_method', new AccessGateTestDummy()));
    }

    public function testForUserMethodAttachesANewUserToANewGateInstance()
    {
        $gate = $this->getBasicGate();

        // Assert that the callback receives the new user with ID of 2 instead of ID of 1...
        $gate->define('foo', function ($user) {
            $this->assertEquals(2, $user->id);

            return true;
        });

        $user = $this->getUser();
        $user->id = 2;
        $this->assertTrue($gate->forUser($user)->check('foo'));
    }

    public function testForUserMethodAttachesANewUserToANewGateInstanceWithGuessCallback()
    {
        $gate = $this->getBasicGate();

        $gate->define('foo', function () {
            return true;
        });

        $counter = 0;
        $guesserCallback = function () use (&$counter) {
            ++$counter;
        };
        $gate->guessPolicyNamesUsing($guesserCallback);
        $gate->getPolicyFor('fooClass');
        $this->assertEquals(1, $counter);

        // now the guesser callback should be present on the new gate as well
        $newGate = $gate->forUser($this->getUser());

        $newGate->getPolicyFor('fooClass');
        $this->assertEquals(2, $counter);

        $newGate->getPolicyFor('fooClass');
        $this->assertEquals(3, $counter);
    }

    /**
     * @dataProvider notCallableDataProvider
     * @param mixed $callback
     */
    public function testDefineSecondParameterShouldBeStringOrCallable($callback)
    {
        $this->expectException(InvalidArgumentException::class);

        $gate = $this->getBasicGate();

        $gate->define('foo', $callback);
    }

    /**
     * @return array
     */
    public static function notCallableDataProvider()
    {
        return [
            [1],
            [new stdClass()],
            [[]],
            [1.1],
        ];
    }

    public function testAuthorizeThrowsUnauthorizedException()
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('You are not an admin.');
        $this->expectExceptionCode(0);

        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicy::class);

        $gate->authorize('create', new AccessGateTestDummy());
    }

    public function testAuthorizeThrowsUnauthorizedExceptionWithCustomStatusCode()
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Not allowed to view as it is not published.');
        $this->expectExceptionCode('unpublished');

        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicyWithCode::class);

        $gate->authorize('view', new AccessGateTestDummy());
    }

    public function testAuthorizeWithPolicyThatReturnsDeniedResponseObjectThrowsException()
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Not allowed.');
        $this->expectExceptionCode('some_code');

        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicyWithDeniedResponseObject::class);

        $gate->authorize('create', new AccessGateTestDummy());
    }

    public function testPolicyThatThrowsAuthorizationExceptionIsCaughtInInspect()
    {
        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicyThrowingAuthorizationException::class);

        $response = $gate->inspect('create', new AccessGateTestDummy());

        $this->assertTrue($response->denied());
        $this->assertFalse($response->allowed());
        $this->assertSame('Not allowed.', $response->message());
        $this->assertSame('some_code', $response->code());
    }

    public function testAuthorizeReturnsAllowedResponse()
    {
        $gate = $this->getBasicGate(true);

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicy::class);

        $check = $gate->check('create', new AccessGateTestDummy());
        $response = $gate->authorize('create', new AccessGateTestDummy());

        $this->assertInstanceOf(Response::class, $response);
        $this->assertNull($response->message());
        $this->assertTrue($check);
    }

    public function testResponseReturnsResponseWhenAbilityGranted()
    {
        $gate = $this->getBasicGate(true);

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicyWithCode::class);

        $response = $gate->inspect('view', new AccessGateTestDummy());

        $this->assertInstanceOf(Response::class, $response);
        $this->assertNull($response->message());
        $this->assertTrue($response->allowed());
        $this->assertFalse($response->denied());
        $this->assertNull($response->code());
    }

    public function testResponseReturnsResponseWhenAbilityDenied()
    {
        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicyWithCode::class);

        $response = $gate->inspect('view', new AccessGateTestDummy());

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame('Not allowed to view as it is not published.', $response->message());
        $this->assertFalse($response->allowed());
        $this->assertTrue($response->denied());
        $this->assertEquals($response->code(), 'unpublished');
    }

    public function testAuthorizeReturnsAnAllowedResponseForATruthyReturn()
    {
        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicy::class);

        $response = $gate->authorize('update', new AccessGateTestDummy());

        $this->assertInstanceOf(Response::class, $response);
        $this->assertNull($response->message());
    }

    public function testAnyAbilityCheckPassesIfAllPass()
    {
        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicyWithAllPermissions::class);

        $this->assertTrue($gate->any(['edit', 'update'], new AccessGateTestDummy()));
    }

    public function testAnyAbilityCheckPassesIfAtLeastOnePasses()
    {
        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicyWithMixedPermissions::class);

        $this->assertTrue($gate->any(['edit', 'update'], new AccessGateTestDummy()));
    }

    public function testAnyAbilityCheckFailsIfNonePass()
    {
        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicyWithNoPermissions::class);

        $this->assertFalse($gate->any(['edit', 'update'], new AccessGateTestDummy()));
    }

    public function testNoneAbilityCheckPassesIfAllFail()
    {
        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicyWithNoPermissions::class);

        $this->assertTrue($gate->none(['edit', 'update'], new AccessGateTestDummy()));
    }

    public function testEveryAbilityCheckPassesIfAllPass()
    {
        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicyWithAllPermissions::class);

        $this->assertTrue($gate->check(['edit', 'update'], new AccessGateTestDummy()));
    }

    public function testEveryAbilityCheckFailsIfAtLeastOneFails()
    {
        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicyWithMixedPermissions::class);

        $this->assertFalse($gate->check(['edit', 'update'], new AccessGateTestDummy()));
    }

    public function testEveryAbilityCheckFailsIfNonePass()
    {
        $gate = $this->getBasicGate();

        $gate->policy(AccessGateTestDummy::class, AccessGateTestPolicyWithNoPermissions::class);

        $this->assertFalse($gate->check(['edit', 'update'], new AccessGateTestDummy()));
    }

    /**
     * @dataProvider hasAbilitiesTestDataProvider
     *
     * @param array $abilitiesToSet
     * @param array|string $abilitiesToCheck
     * @param bool $expectedHasValue
     */
    public function testHasAbilities($abilitiesToSet, $abilitiesToCheck, $expectedHasValue)
    {
        $gate = $this->getBasicGate();

        $gate->resource('test', AccessGateTestResource::class, $abilitiesToSet);

        $this->assertEquals($expectedHasValue, $gate->has($abilitiesToCheck));
    }

    public static function hasAbilitiesTestDataProvider()
    {
        $abilities = ['foo' => 'foo', 'bar' => 'bar'];
        $noAbilities = [];

        return [
            [$abilities, ['test.foo', 'test.bar'], true],
            [$abilities, ['test.bar', 'test.foo'], true],
            [$abilities, ['test.bar', 'test.foo', 'test.baz'], false],
            [$abilities, ['test.bar'], true],
            [$abilities, ['baz'], false],
            [$abilities, [''], false],
            [$abilities, [], true],
            [$abilities, 'test.bar', true],
            [$abilities, 'test.foo', true],
            [$abilities, '', false],
            [$noAbilities, '', false],
            [$noAbilities, [], true],
        ];
    }

    public function testClassesCanBeDefinedAsCallbacksUsingAtNotationForGuests()
    {
        $gate = new Gate($this->getContainer(), function () {
        });

        $gate->define('foo', AccessGateTestClassForGuest::class . '@foo');
        $gate->define('obj_foo', [new AccessGateTestClassForGuest(), 'foo']);
        $gate->define('static_foo', [AccessGateTestClassForGuest::class, 'staticFoo']);
        $gate->define('static_@foo', AccessGateTestClassForGuest::class . '@staticFoo');
        $gate->define('bar', AccessGateTestClassForGuest::class . '@bar');
        $gate->define('invokable', AccessGateTestGuestInvokableClass::class);
        $gate->define('nullable_invokable', AccessGateTestGuestNullableInvokable::class);
        $gate->define('absent_invokable', 'someAbsentClass');

        AccessGateTestClassForGuest::$calledMethod = '';

        $this->assertTrue($gate->check('foo'));
        $this->assertSame('foo was called', AccessGateTestClassForGuest::$calledMethod);

        $this->assertTrue($gate->check('static_foo'));
        $this->assertSame('static foo was invoked', AccessGateTestClassForGuest::$calledMethod);

        $this->assertTrue($gate->check('bar'));
        $this->assertSame('bar got invoked', AccessGateTestClassForGuest::$calledMethod);

        $this->assertTrue($gate->check('static_@foo'));
        $this->assertSame('static foo was invoked', AccessGateTestClassForGuest::$calledMethod);

        $this->assertTrue($gate->check('invokable'));
        $this->assertSame('__invoke was called', AccessGateTestGuestInvokableClass::$calledMethod);

        $this->assertTrue($gate->check('nullable_invokable'));
        $this->assertSame('Nullable __invoke was called', AccessGateTestGuestNullableInvokable::$calledMethod);

        $this->assertFalse($gate->check('absent_invokable'));
    }

    protected function getBasicGate($isAdmin = false)
    {
        return new Gate($this->getContainer(), function () use ($isAdmin) {
            $user = $this->getUser();
            $user->isAdmin = $isAdmin;
            return $user;
        });
    }

    protected function getUser()
    {
        return new User();
    }
}

class User implements AuthenticatableInterface
{
    public $id;

    public $isAdmin;

    public $password;

    public $rememberToken;

    public $rememberTokenName;

    public function __construct(array $attributes = [])
    {
        $this->id = $attributes['id'] ?? 1;
        $this->isAdmin = $attributes['isAdmin'] ?? false;
        $this->password = $attributes['password'] ?? '';
        $this->rememberToken = $attributes['rememberToken'] ?? '';
        $this->rememberTokenName = $attributes['rememberTokenName'] ?? '';
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier()
    {
        return $this->id;
    }

    public function getAuthPassword(): ?string
    {
        return $this->password;
    }

    public function getRememberToken(): ?string
    {
        return $this->rememberToken;
    }

    public function setRememberToken($value)
    {
        $this->rememberToken = (string) $value;
        return $this;
    }

    public function getRememberTokenName(): ?string
    {
        return $this->rememberTokenName;
    }
}

class AccessGateTestClassForGuest
{
    public static $calledMethod = null;

    public function foo($user = null)
    {
        static::$calledMethod = 'foo was called';

        return true;
    }

    public static function staticFoo($user = null)
    {
        static::$calledMethod = 'static foo was invoked';

        return true;
    }

    public function bar(?User $user)
    {
        static::$calledMethod = 'bar got invoked';

        return true;
    }
}

class AccessGateTestStaticClass
{
    public static function foo($user)
    {
        return $user->id === 1;
    }
}

class AccessGateTestClass
{
    public function foo($user)
    {
        return $user->id === 1;
    }
}

class AccessGateTestInvokableClass
{
    public function __invoke($user)
    {
        return $user->id === 1;
    }
}

class AccessGateTestGuestInvokableClass
{
    public static $calledMethod = null;

    public function __invoke($user = null)
    {
        static::$calledMethod = '__invoke was called';

        return true;
    }
}

class AccessGateTestGuestNullableInvokable
{
    public static $calledMethod = null;

    public function __invoke(?User $user)
    {
        static::$calledMethod = 'Nullable __invoke was called';

        return true;
    }
}

interface AccessGateTestDummyInterface
{
}

class AccessGateTestDummy implements AccessGateTestDummyInterface
{
}

class AccessGateTestSubDummy extends AccessGateTestDummy
{
}

class AccessGateTestPolicy
{
    use HandlesAuthorization;

    public function createAny($user, $additional)
    {
        return $additional;
    }

    public function create($user)
    {
        return $user->isAdmin ? $this->allow() : $this->deny('You are not an admin.');
    }

    public function updateAny($user, AccessGateTestDummy $dummy)
    {
        return ! $user->isAdmin;
    }

    public function update($user, AccessGateTestDummy $dummy)
    {
        return ! $user->isAdmin;
    }

    public function updateDash($user, AccessGateTestDummy $dummy)
    {
        return $user instanceof User;
    }
}

class AccessGateTestPolicyWithBefore
{
    public function before($user, $ability)
    {
        return true;
    }

    public function update($user, AccessGateTestDummy $dummy)
    {
        return false;
    }
}

class AccessGateTestResource
{
    public function view($user)
    {
        return true;
    }

    public function create($user)
    {
        return true;
    }

    public function update($user, AccessGateTestDummy $dummy)
    {
        return true;
    }

    public function delete($user, AccessGateTestDummy $dummy)
    {
        return true;
    }
}

class AccessGateTestCustomResource
{
    public function foo($user)
    {
        return true;
    }

    public function bar($user)
    {
        return true;
    }
}

class AccessGateTestPolicyWithMixedPermissions
{
    public function edit($user, AccessGateTestDummy $dummy)
    {
        return false;
    }

    public function update($user, AccessGateTestDummy $dummy)
    {
        return true;
    }
}

class AccessGateTestPolicyWithNoPermissions
{
    public function edit($user, AccessGateTestDummy $dummy)
    {
        return false;
    }

    public function update($user, AccessGateTestDummy $dummy)
    {
        return false;
    }
}

class AccessGateTestPolicyWithAllPermissions
{
    public function edit($user, AccessGateTestDummy $dummy)
    {
        return true;
    }

    public function update($user, AccessGateTestDummy $dummy)
    {
        return true;
    }
}

class AccessGateTestPolicyThatAllowsGuests
{
    public function before(?User $user)
    {
        $_SERVER['__hyperf.testBefore'] = true;
    }

    public function edit(?User $user, AccessGateTestDummy $dummy)
    {
        return true;
    }

    public function update($user, AccessGateTestDummy $dummy)
    {
        return true;
    }
}

class AccessGateTestPolicyWithNonGuestBefore
{
    public function before(User $user)
    {
        $_SERVER['__hyperf.testBefore'] = true;
    }

    public function edit(?User $user, AccessGateTestDummy $dummy)
    {
        return true;
    }

    public function update($user, AccessGateTestDummy $dummy)
    {
        return true;
    }
}

class AccessGateTestBeforeCallback
{
    public function allowEverything($user = null)
    {
        return true;
    }

    public static function allowEverythingStatically($user = null)
    {
        return true;
    }
}

class AccessGateTestPolicyWithCode
{
    use HandlesAuthorization;

    public function view($user)
    {
        if (! $user->isAdmin) {
            return $this->deny('Not allowed to view as it is not published.', 'unpublished');
        }

        return true;
    }
}

class AccessGateTestPolicyWithDeniedResponseObject
{
    public function create()
    {
        return Response::deny('Not allowed.', 'some_code');
    }
}

class AccessGateTestPolicyThrowingAuthorizationException
{
    public function create()
    {
        throw new AuthorizationException('Not allowed.', 'some_code');
    }
}
