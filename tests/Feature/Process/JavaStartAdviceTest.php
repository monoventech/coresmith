<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\Process\ProcessStartAdvice;

function javaStartApp(string $framework): Application
{
    return new Application(['type' => ApplicationType::Java, 'framework' => $framework, 'slug' => 'shop', 'base_port' => 4500, 'env_variables' => '']);
}

const JAVA_POSTGRES_REFUSED = "HikariPool-1 - Exception during pool initialization.\norg.postgresql.util.PSQLException: Connection to localhost:5432 refused. Check that the hostname and port are correct and that the postmaster is accepting TCP/IP connections.";

test("a Java app that can't reach its database is told which variables point it at one", function (string $framework, string $output, string $variables) {
    expect((new ProcessStartAdvice)->for(javaStartApp($framework), $output))->toBe("The app couldn't reach its database. Add one on the server's Databases tab, then set {$variables} in the app's environment and deploy again.");
})->with([
    'Spring Boot on PostgreSQL' => ['spring-boot', JAVA_POSTGRES_REFUSED, 'SPRING_DATASOURCE_URL, SPRING_DATASOURCE_USERNAME and SPRING_DATASOURCE_PASSWORD'],
    'JHipster on MySQL' => ['jhipster', "com.mysql.cj.jdbc.exceptions.CommunicationsException: Communications link failure\n\nThe last packet sent successfully to the server was 0 milliseconds ago.", 'SPRING_DATASOURCE_URL, SPRING_DATASOURCE_USERNAME and SPRING_DATASOURCE_PASSWORD'],
    'Spring Boot with no database set' => ['spring-boot', "Description:\n\nFailed to configure a DataSource: 'url' attribute is not specified and no embedded datasource could be configured.", 'SPRING_DATASOURCE_URL, SPRING_DATASOURCE_USERNAME and SPRING_DATASOURCE_PASSWORD'],
    'Quarkus' => ['quarkus', JAVA_POSTGRES_REFUSED, 'QUARKUS_DATASOURCE_JDBC_URL, QUARKUS_DATASOURCE_USERNAME and QUARKUS_DATASOURCE_PASSWORD'],
    'Micronaut' => ['micronaut', JAVA_POSTGRES_REFUSED, 'DATASOURCES_DEFAULT_URL, DATASOURCES_DEFAULT_USERNAME and DATASOURCES_DEFAULT_PASSWORD'],
    'Javalin' => ['javalin', JAVA_POSTGRES_REFUSED, 'the variables the app reads its database address from'],
]);

test("a JHipster gateway or microservice that can't reach its registry is told what it needs", function (string $output, string $advice) {
    expect((new ProcessStartAdvice)->for(javaStartApp('jhipster'), $output))->toBe($advice);
})->with([
    'Consul' => ["com.ecwid.consul.transport.TransportException: java.net.ConnectException: Connection refused\n\tat com.ecwid.consul.transport.AbstractHttpTransport.executeRequest", "The app couldn't reach Consul. JHipster gateways and microservices register with Consul, which Coresmith doesn't run, so run it where this server can reach it, set SPRING_CLOUD_CONSUL_HOST to its address in the app's environment, then deploy again."],
    'Eureka' => ['com.netflix.discovery.shared.transport.TransportException: Cannot execute request on any known server', "The app couldn't reach its Eureka registry. JHipster gateways and microservices register with one, which Coresmith doesn't run, so run it where this server can reach it, set EUREKA_CLIENT_SERVICE_URL_DEFAULTZONE to its address in the app's environment, then deploy again."],
]);

test('a Java app that ran out of heap is told to give it more memory', function () {
    expect((new ProcessStartAdvice)->for(javaStartApp('spring-boot'), "Exception in thread \"main\" java.lang.OutOfMemoryError: Java heap space\n"))
        ->toBe("The app ran out of Java heap. Pick a bigger size on the app page, or give the heap a larger share of the app's memory in JAVA_OPTS, then deploy again.");
});

test("Spring Boot's own words for a taken port get the port advice", function () {
    expect((new ProcessStartAdvice)->for(javaStartApp('spring-boot'), "Description:\n\nWeb server failed to start. Port 4500 was already in use.\n"))
        ->toBe('Port 4500 is already taken by another process. Find it with ss -tlnp | grep 4500, stop it, then deploy again.');
});

test('output with nothing recognisable has no specific advice, so the general advice stands', function () {
    expect((new ProcessStartAdvice)->specific(javaStartApp('spring-boot'), "Started ShopApplication in 4.2 seconds\n"))->toBeNull()
        ->and((new ProcessStartAdvice)->for(javaStartApp('spring-boot'), "Started ShopApplication in 4.2 seconds\n"))->toBe('The process log says why. Fix it and deploy again, or roll back to the previous release.');
});

test('the database advice is only for Java apps, whose drivers say these words', function () {
    expect((new ProcessStartAdvice)->specific(new Application(['type' => ApplicationType::Php, 'slug' => 'shop', 'base_port' => 4500, 'env_variables' => '']), JAVA_POSTGRES_REFUSED))->toBeNull();
});
