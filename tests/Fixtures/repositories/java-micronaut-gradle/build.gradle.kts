plugins {
    id("com.gradleup.shadow") version "8.3.9"
    id("io.micronaut.application") version "4.5.4"
}

version = "0.1"
group = "com.acme"

repositories {
    mavenCentral()
}

dependencies {
    annotationProcessor("io.micronaut:micronaut-http-validation")
    implementation("io.micronaut:micronaut-http-server-netty")
    runtimeOnly("ch.qos.logback:logback-classic")
}

application {
    mainClass = "com.acme.Application"
}

java {
    toolchain {
        languageVersion = JavaLanguageVersion.of(21)
    }
}
