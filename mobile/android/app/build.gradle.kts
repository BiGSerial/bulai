import java.util.Properties

plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.compose)
    alias(libs.plugins.kotlin.serialization)
}

// Assinatura da versão de publicação: keystore.properties (fora do Git), criado por scripts/criar-chave-release.sh.
val keystoreProperties = Properties().apply {
    val file = rootProject.file("keystore.properties")
    if (file.exists()) {
        file.inputStream().use { load(it) }
    }
}
val baseUrl = (findProperty("bulaiBaseUrl") as String?)?.trimEnd('/') ?: "https://bulai.vixforge.com.br"

// Versão = a última tag android-v* do Git (ex.: android-v1.0.0-preview.4 → 1.0.0-preview.4). -PbulaiVersao=… força outra;
// sem Git ou sem tags (clone raso), cai em 1.0.0. O versionCode cresce com a versão: 1.0.0-preview.4 → 1000004, 1.0.0 → 1000099.
val versaoDoApp: String = (findProperty("bulaiVersao") as String?)?.removePrefix("android-v") ?: runCatching {
    providers.exec {
        commandLine("git", "-c", "versionsort.suffix=-", "tag", "--list", "android-v*", "--sort=-v:refname")
        isIgnoreExitValue = true
    }.standardOutput.asText.get().lineSequence().map { it.trim() }.firstOrNull { it.isNotEmpty() }?.removePrefix("android-v")
}.getOrNull() ?: "1.0.0"

fun codigoDaVersao(versao: String): Int {
    val partes = Regex("""^(\d+)\.(\d+)\.(\d+)(?:-[a-z]+\.(\d+))?""").find(versao)?.groupValues ?: return 1
    val (maior, menor, correcao) = partes.drop(1).take(3).map { it.toInt() }
    val previa = partes[4].toIntOrNull()?.coerceAtMost(98) ?: 99
    return maior * 1_000_000 + menor * 10_000 + correcao * 100 + previa
}

android {
    namespace = "br.com.vixforge.bulai"
    compileSdk = 37

    defaultConfig {
        applicationId = "br.com.vixforge.bulai"
        minSdk = 26
        targetSdk = 36
        versionCode = codigoDaVersao(versaoDoApp)
        versionName = versaoDoApp

        buildConfigField("String", "BASE_URL", "\"$baseUrl\"")
        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
    }

    signingConfigs {
        if (keystoreProperties.isNotEmpty()) {
            create("release") {
                storeFile = rootProject.file(keystoreProperties.getProperty("storeFile"))
                storePassword = keystoreProperties.getProperty("storePassword")
                keyAlias = keystoreProperties.getProperty("keyAlias")
                keyPassword = keystoreProperties.getProperty("keyPassword")
            }
        }
    }

    buildTypes {
        debug {
            applicationIdSuffix = ".debug"
            versionNameSuffix = "-debug"
        }
        release {
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
            signingConfig = signingConfigs.findByName("release")
        }
    }

    buildFeatures {
        buildConfig = true
        compose = true
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
}

dependencies {
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.activity.compose)
    implementation(libs.androidx.work.runtime.ktx)
    implementation(libs.androidx.core.splashscreen)
    implementation(libs.androidx.browser)
    implementation(libs.androidx.navigation.compose)
    implementation(libs.androidx.lifecycle.viewmodel.compose)
    implementation(libs.androidx.lifecycle.runtime.compose)

    implementation(platform(libs.compose.bom))
    implementation(libs.compose.ui)
    implementation(libs.compose.ui.tooling.preview)
    implementation(libs.compose.material3)
    implementation(libs.compose.material.icons)
    debugImplementation(libs.compose.ui.tooling)

    implementation(libs.kotlinx.coroutines.android)
    implementation(libs.kotlinx.serialization.json)
    implementation(libs.retrofit)
    implementation(libs.retrofit.kotlinx.serialization)
    implementation(libs.okhttp)

    // QR Code do convite de cuidador: gerar (ZXing, Java puro) e ler (leitor do Google Play, sem permissão de câmera).
    implementation(libs.zxing.core)
    implementation(libs.play.services.code.scanner)

    // org.json real nos testes de JVM (o android.jar só traz stubs).
    testImplementation(libs.junit)
    testImplementation(libs.json)
    testImplementation(libs.kotlinx.coroutines.test)
    testImplementation(libs.okhttp.mockwebserver)
}
