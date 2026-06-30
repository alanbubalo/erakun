# eRakun

A mock **information intermediary** (_informacijski posrednik_) for Croatia's Fiscalization 2.0 system.

> This is an educational/school project. Not intended for production use or actual certification.

## Tech Stack

- **PHP 8.5+** / **Laravel 12**
- **Java 8+ (JRE)** — runs Saxon for Schematron validation (see [External dependencies](#external-dependencies))
- **Pest** — testing
- **Larastan** — static analysis
- **Pint** — code formatting

## Getting Started

```bash
composer setup
composer dev
```

## External dependencies

UBL invoices are validated against the EN 16931 and HR-CIUS **Schematron** rule sets.
Those rules are authored in **XSLT 2.0**, which PHP's built-in `XSLTProcessor`
(libxslt, XSLT 1.0 only) cannot run — so validation shells out to the
[Saxon-HE](https://www.saxonica.com/) XSLT processor, which requires a **Java
runtime**. The Saxon jars themselves are vendored under `resources/schemas/saxon/`.

A *headless JRE* is sufficient (no full JDK needed):

```bash
# Debian/Ubuntu
apt-get install -y openjdk-21-jre-headless
# Alpine
apk add --no-cache openjdk21-jre-headless
# macOS (Homebrew)
brew install --cask temurin
```

The validator invokes the `java` binary configured by the `JAVA_BIN` env var
(`config/services.php` → `saxon.java_binary`), defaulting to `java` on `PATH`.
On macOS, `/usr/bin/java` is only a stub until a JDK/JRE is installed, so set
`JAVA_BIN` to a real runtime if `java` doesn't resolve:

```dotenv
JAVA_BIN=java
```

## Deployment

The host (or container) running the app must provide a Java runtime alongside PHP
— it's an external CLI dependency, not a Composer package. Install a headless JRE
(see above) and leave `JAVA_BIN=java`, or point it at the runtime's path.

For Docker, add the JRE to your PHP image:

```dockerfile
FROM php:8.5-fpm
RUN apt-get update \
 && apt-get install -y --no-install-recommends openjdk-21-jre-headless \
 && rm -rf /var/lib/apt/lists/*
```

> **Note:** validation runs synchronously per request and starts a fresh JVM for
> each rule set, so each invoice transition pays JVM warmup (~1–2s). Results are
> cached by document hash. On environments where you can't install system packages
> (some serverless/PaaS hosts), run Saxon as a separate service and call it over HTTP.
