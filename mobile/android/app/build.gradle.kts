import java.util.Properties

plugins {
    alias(libs.plugins.android.application)
}

// Assinatura da versão de publicação: keystore.properties (fora do Git), criado por scripts/criar-chave-release.sh.
val keystoreProperties = Properties().apply {
    val file = rootProject.file("keystore.properties")
    if (file.exists()) {
        file.inputStream().use { load(it) }
    }
}
val baseUrl = (findProperty("bulaiBaseUrl") as String?)?.trimEnd('/') ?: "https://bulai.vixforge.com.br"

android {
    namespace = "br.com.vixforge.bulai"
    compileSdk = 37

    defaultConfig {
        applicationId = "br.com.vixforge.bulai"
        minSdk = 26
        targetSdk = 36
        versionCode = 1
        versionName = "1.0.0"

        buildConfigField("String", "BASE_URL", "\"$baseUrl\"")
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
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
}

dependencies {
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.activity.ktx)
    implementation(libs.androidx.work.runtime.ktx)
    implementation(libs.androidx.core.splashscreen)

    // org.json real nos testes de JVM (o android.jar só traz stubs).
    testImplementation(libs.junit)
    testImplementation(libs.json)
}
