#!/usr/bin/env python3
"""Gera BulaIA.xcodeproj (formato do Xcode 16+, com pastas sincronizadas) e o esquema compartilhado.

As pastas BulaIA/ e Widget/ entram inteiras nos alvos (arquivo novo não precisa mexer no projeto). O núcleo é o pacote
Swift local BulaiCore/ (também testado com `swift test`). Rode de novo só se mudar alvos, configurações ou capacidades:

    python3 mobile/ios/scripts/gerar-projeto.py
"""
import hashlib
import os

IOS = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
PROJETO = os.path.join(IOS, "BulaIA.xcodeproj")


def ident(nome):
    """Identificador estável de 24 dígitos hexadecimais (o mesmo a cada geração)."""
    return hashlib.sha1(("bulai-ios:" + nome).encode()).hexdigest()[:24].upper()


I = {nome: ident(nome) for nome in [
    "projeto", "grupo-principal", "grupo-produtos", "grupo-config", "pasta-app", "pasta-widget", "ref-pacote",
    "produto-app", "produto-widget", "alvo-app", "alvo-widget",
    "fontes-app", "frameworks-app", "recursos-app", "embutir-app", "fontes-widget", "frameworks-widget", "recursos-widget",
    "pacote", "dep-nucleo-app", "dep-nucleo-widget", "arquivo-nucleo-app", "arquivo-nucleo-widget", "arquivo-widget-embutido",
    "proxy-widget", "dependencia-widget",
    "lista-projeto", "lista-app", "lista-widget",
    "projeto-debug", "projeto-release", "app-debug", "app-release", "widget-debug", "widget-release",
    "cfg-base", "cfg-debug", "cfg-release", "cfg-exemplo", "info-app", "info-widget", "ent-app", "ent-widget", "ent-app-simples", "ent-widget-simples",
]}

ARQUIVOS_DE_CONFIG = [
    ("cfg-base", "Base.xcconfig", "text.xcconfig"),
    ("cfg-debug", "Debug.xcconfig", "text.xcconfig"),
    ("cfg-release", "Release.xcconfig", "text.xcconfig"),
    ("cfg-exemplo", "Local.xcconfig.example", "text"),
    ("info-app", "BulaIA-Info.plist", "text.plist.xml"),
    ("info-widget", "Widget-Info.plist", "text.plist.xml"),
    ("ent-app", "BulaIA.entitlements", "text.plist.entitlements"),
    ("ent-widget", "Widget.entitlements", "text.plist.entitlements"),
    ("ent-app-simples", "BulaIA-Simples.entitlements", "text.plist.entitlements"),
    ("ent-widget-simples", "Widget-Simples.entitlements", "text.plist.entitlements"),
]


def configuracoes_do_projeto(depuracao):
    base = {
        "ALWAYS_SEARCH_USER_PATHS": "NO",
        "ASSETCATALOG_COMPILER_GENERATE_SWIFT_ASSET_SYMBOL_EXTENSIONS": "YES",
        "CLANG_ENABLE_MODULES": "YES",
        "CLANG_ENABLE_OBJC_ARC": "YES",
        "COPY_PHASE_STRIP": "NO",
        "ENABLE_STRICT_OBJC_MSGSEND": "YES",
        "ENABLE_USER_SCRIPT_SANDBOXING": "YES",
        "GCC_C_LANGUAGE_STANDARD": "gnu17",
        "LOCALIZATION_PREFERS_STRING_CATALOGS": "YES",
        "SDKROOT": "iphoneos",
        "SWIFT_EMIT_LOC_STRINGS": "YES",
    }
    if depuracao:
        base.update({
            "DEBUG_INFORMATION_FORMAT": "dwarf",
            "ENABLE_TESTABILITY": "YES",
            "GCC_OPTIMIZATION_LEVEL": "0",
            "ONLY_ACTIVE_ARCH": "YES",
            "SWIFT_OPTIMIZATION_LEVEL": '"-Onone"',
        })
    else:
        base.update({
            "DEBUG_INFORMATION_FORMAT": '"dwarf-with-dsym"',
            "ENABLE_NS_ASSERTIONS": "NO",
            "SWIFT_COMPILATION_MODE": "wholemodule",
            "VALIDATE_PRODUCT": "YES",
        })
    return base


CONFIG_APP = {
    "ASSETCATALOG_COMPILER_APPICON_NAME": "AppIcon",
    "ASSETCATALOG_COMPILER_GLOBAL_ACCENT_COLOR_NAME": "AccentColor",
    "CODE_SIGN_ENTITLEMENTS": '"$(BULAI_ENTITLEMENTS_APP)"',
    "ENABLE_PREVIEWS": "YES",
    "GENERATE_INFOPLIST_FILE": "NO",
    "INFOPLIST_FILE": "Config/BulaIA-Info.plist",
    "LD_RUNPATH_SEARCH_PATHS": '"$(inherited) @executable_path/Frameworks"',
    "PRODUCT_BUNDLE_IDENTIFIER": '"$(BULAI_BUNDLE_ID)"',
    "PRODUCT_NAME": "BulaIA",
    "TARGETED_DEVICE_FAMILY": '"1,2"',
}

CONFIG_WIDGET = {
    "ASSETCATALOG_COMPILER_GLOBAL_ACCENT_COLOR_NAME": "AccentColor",
    "ASSETCATALOG_COMPILER_WIDGET_BACKGROUND_COLOR_NAME": "FundoDoWidget",
    "CODE_SIGN_ENTITLEMENTS": '"$(BULAI_ENTITLEMENTS_WIDGET)"',
    "GENERATE_INFOPLIST_FILE": "NO",
    "INFOPLIST_FILE": "Config/Widget-Info.plist",
    "LD_RUNPATH_SEARCH_PATHS": '"$(inherited) @executable_path/Frameworks @executable_path/../../Frameworks"',
    "PRODUCT_BUNDLE_IDENTIFIER": '"$(BULAI_BUNDLE_ID).widget"',
    "PRODUCT_NAME": "BulaiWidget",
    "SKIP_INSTALL": "YES",
    "TARGETED_DEVICE_FAMILY": '"1,2"',
}


def configuracao(chave, nome, ajustes, base=None):
    linhas = [f"\t\t{I[chave]} /* {nome} */ = {{", "\t\t\tisa = XCBuildConfiguration;"]
    if base:
        linhas.append(f"\t\t\tbaseConfigurationReference = {I[base]} /* {base_nome(base)} */;")
    linhas.append("\t\t\tbuildSettings = {")
    for k in sorted(ajustes):
        linhas.append(f"\t\t\t\t{k} = {ajustes[k]};")
    linhas += ["\t\t\t};", f"\t\t\tname = {nome};", "\t\t};"]
    return "\n".join(linhas)


def base_nome(chave):
    return {"cfg-debug": "Debug.xcconfig", "cfg-release": "Release.xcconfig"}[chave]


def projeto():
    o = []
    o.append("/* Begin PBXBuildFile section */")
    o.append(f"\t\t{I['arquivo-nucleo-app']} /* BulaiCore in Frameworks */ = {{isa = PBXBuildFile; productRef = {I['dep-nucleo-app']} /* BulaiCore */; }};")
    o.append(f"\t\t{I['arquivo-nucleo-widget']} /* BulaiCore in Frameworks */ = {{isa = PBXBuildFile; productRef = {I['dep-nucleo-widget']} /* BulaiCore */; }};")
    o.append(f"\t\t{I['arquivo-widget-embutido']} /* BulaiWidget.appex in Embed Foundation Extensions */ = {{isa = PBXBuildFile; fileRef = {I['produto-widget']} /* BulaiWidget.appex */; settings = {{ATTRIBUTES = (RemoveHeadersOnCopy, ); }}; }};")
    o.append("/* End PBXBuildFile section */\n")

    o.append("/* Begin PBXContainerItemProxy section */")
    o.append(f"""\t\t{I['proxy-widget']} /* PBXContainerItemProxy */ = {{
\t\t\tisa = PBXContainerItemProxy;
\t\t\tcontainerPortal = {I['projeto']} /* Project object */;
\t\t\tproxyType = 1;
\t\t\tremoteGlobalIDString = {I['alvo-widget']};
\t\t\tremoteInfo = BulaiWidget;
\t\t}};""")
    o.append("/* End PBXContainerItemProxy section */\n")

    o.append("/* Begin PBXCopyFilesBuildPhase section */")
    o.append(f"""\t\t{I['embutir-app']} /* Embed Foundation Extensions */ = {{
\t\t\tisa = PBXCopyFilesBuildPhase;
\t\t\tbuildActionMask = 2147483647;
\t\t\tdstPath = "";
\t\t\tdstSubfolderSpec = 13;
\t\t\tfiles = (
\t\t\t\t{I['arquivo-widget-embutido']} /* BulaiWidget.appex in Embed Foundation Extensions */,
\t\t\t);
\t\t\tname = "Embed Foundation Extensions";
\t\t\trunOnlyForDeploymentPostprocessing = 0;
\t\t}};""")
    o.append("/* End PBXCopyFilesBuildPhase section */\n")

    o.append("/* Begin PBXFileReference section */")
    o.append(f"\t\t{I['produto-app']} /* BulaIA.app */ = {{isa = PBXFileReference; explicitFileType = wrapper.application; includeInIndex = 0; path = BulaIA.app; sourceTree = BUILT_PRODUCTS_DIR; }};")
    o.append(f"\t\t{I['produto-widget']} /* BulaiWidget.appex */ = {{isa = PBXFileReference; explicitFileType = \"wrapper.app-extension\"; includeInIndex = 0; path = BulaiWidget.appex; sourceTree = BUILT_PRODUCTS_DIR; }};")
    o.append(f"\t\t{I['ref-pacote']} /* BulaiCore */ = {{isa = PBXFileReference; lastKnownFileType = wrapper; path = BulaiCore; sourceTree = \"<group>\"; }};")
    for chave, nome, tipo in ARQUIVOS_DE_CONFIG:
        o.append(f"\t\t{I[chave]} /* {nome} */ = {{isa = PBXFileReference; lastKnownFileType = {tipo}; path = {nome}; sourceTree = \"<group>\"; }};")
    o.append("/* End PBXFileReference section */\n")

    o.append("/* Begin PBXFileSystemSynchronizedRootGroup section */")
    for chave, nome in (("pasta-app", "BulaIA"), ("pasta-widget", "Widget")):
        o.append(f"""\t\t{I[chave]} /* {nome} */ = {{
\t\t\tisa = PBXFileSystemSynchronizedRootGroup;
\t\t\tpath = {nome};
\t\t\tsourceTree = "<group>";
\t\t}};""")
    o.append("/* End PBXFileSystemSynchronizedRootGroup section */\n")

    o.append("/* Begin PBXFrameworksBuildPhase section */")
    for fase, arquivo in (("frameworks-app", "arquivo-nucleo-app"), ("frameworks-widget", "arquivo-nucleo-widget")):
        o.append(f"""\t\t{I[fase]} /* Frameworks */ = {{
\t\t\tisa = PBXFrameworksBuildPhase;
\t\t\tbuildActionMask = 2147483647;
\t\t\tfiles = (
\t\t\t\t{I[arquivo]} /* BulaiCore in Frameworks */,
\t\t\t);
\t\t\trunOnlyForDeploymentPostprocessing = 0;
\t\t}};""")
    o.append("/* End PBXFrameworksBuildPhase section */\n")

    config = "\n".join(f"\t\t\t\t{I[c]} /* {n} */," for c, n, _ in ARQUIVOS_DE_CONFIG)
    o.append("/* Begin PBXGroup section */")
    o.append(f"""\t\t{I['grupo-principal']} = {{
\t\t\tisa = PBXGroup;
\t\t\tchildren = (
\t\t\t\t{I['pasta-app']} /* BulaIA */,
\t\t\t\t{I['pasta-widget']} /* Widget */,
\t\t\t\t{I['ref-pacote']} /* BulaiCore */,
\t\t\t\t{I['grupo-config']} /* Config */,
\t\t\t\t{I['grupo-produtos']} /* Products */,
\t\t\t);
\t\t\tsourceTree = "<group>";
\t\t}};
\t\t{I['grupo-config']} /* Config */ = {{
\t\t\tisa = PBXGroup;
\t\t\tchildren = (
{config}
\t\t\t);
\t\t\tpath = Config;
\t\t\tsourceTree = "<group>";
\t\t}};
\t\t{I['grupo-produtos']} /* Products */ = {{
\t\t\tisa = PBXGroup;
\t\t\tchildren = (
\t\t\t\t{I['produto-app']} /* BulaIA.app */,
\t\t\t\t{I['produto-widget']} /* BulaiWidget.appex */,
\t\t\t);
\t\t\tname = Products;
\t\t\tsourceTree = "<group>";
\t\t}};""")
    o.append("/* End PBXGroup section */\n")

    o.append("/* Begin PBXNativeTarget section */")
    o.append(f"""\t\t{I['alvo-app']} /* BulaIA */ = {{
\t\t\tisa = PBXNativeTarget;
\t\t\tbuildConfigurationList = {I['lista-app']} /* Build configuration list for PBXNativeTarget "BulaIA" */;
\t\t\tbuildPhases = (
\t\t\t\t{I['fontes-app']} /* Sources */,
\t\t\t\t{I['frameworks-app']} /* Frameworks */,
\t\t\t\t{I['recursos-app']} /* Resources */,
\t\t\t\t{I['embutir-app']} /* Embed Foundation Extensions */,
\t\t\t);
\t\t\tbuildRules = (
\t\t\t);
\t\t\tdependencies = (
\t\t\t\t{I['dependencia-widget']} /* PBXTargetDependency */,
\t\t\t);
\t\t\tfileSystemSynchronizedGroups = (
\t\t\t\t{I['pasta-app']} /* BulaIA */,
\t\t\t);
\t\t\tname = BulaIA;
\t\t\tpackageProductDependencies = (
\t\t\t\t{I['dep-nucleo-app']} /* BulaiCore */,
\t\t\t);
\t\t\tproductName = BulaIA;
\t\t\tproductReference = {I['produto-app']} /* BulaIA.app */;
\t\t\tproductType = "com.apple.product-type.application";
\t\t}};
\t\t{I['alvo-widget']} /* BulaiWidget */ = {{
\t\t\tisa = PBXNativeTarget;
\t\t\tbuildConfigurationList = {I['lista-widget']} /* Build configuration list for PBXNativeTarget "BulaiWidget" */;
\t\t\tbuildPhases = (
\t\t\t\t{I['fontes-widget']} /* Sources */,
\t\t\t\t{I['frameworks-widget']} /* Frameworks */,
\t\t\t\t{I['recursos-widget']} /* Resources */,
\t\t\t);
\t\t\tbuildRules = (
\t\t\t);
\t\t\tdependencies = (
\t\t\t);
\t\t\tfileSystemSynchronizedGroups = (
\t\t\t\t{I['pasta-widget']} /* Widget */,
\t\t\t);
\t\t\tname = BulaiWidget;
\t\t\tpackageProductDependencies = (
\t\t\t\t{I['dep-nucleo-widget']} /* BulaiCore */,
\t\t\t);
\t\t\tproductName = BulaiWidget;
\t\t\tproductReference = {I['produto-widget']} /* BulaiWidget.appex */;
\t\t\tproductType = "com.apple.product-type.app-extension";
\t\t}};""")
    o.append("/* End PBXNativeTarget section */\n")

    o.append("/* Begin PBXProject section */")
    o.append(f"""\t\t{I['projeto']} /* Project object */ = {{
\t\t\tisa = PBXProject;
\t\t\tattributes = {{
\t\t\t\tBuildIndependentTargetsInParallel = 1;
\t\t\t\tLastSwiftUpdateCheck = 1600;
\t\t\t\tLastUpgradeCheck = 1600;
\t\t\t\tTargetAttributes = {{
\t\t\t\t\t{I['alvo-app']} = {{
\t\t\t\t\t\tCreatedOnToolsVersion = 16.0;
\t\t\t\t\t}};
\t\t\t\t\t{I['alvo-widget']} = {{
\t\t\t\t\t\tCreatedOnToolsVersion = 16.0;
\t\t\t\t\t}};
\t\t\t\t}};
\t\t\t}};
\t\t\tbuildConfigurationList = {I['lista-projeto']} /* Build configuration list for PBXProject "BulaIA" */;
\t\t\tdevelopmentRegion = "pt-BR";
\t\t\thasScannedForEncodings = 0;
\t\t\tknownRegions = (
\t\t\t\tBase,
\t\t\t\t"pt-BR",
\t\t\t);
\t\t\tmainGroup = {I['grupo-principal']};
\t\t\tminimizedProjectReferenceProxies = 1;
\t\t\tpackageReferences = (
\t\t\t\t{I['pacote']} /* XCLocalSwiftPackageReference "BulaiCore" */,
\t\t\t);
\t\t\tpreferredProjectObjectVersion = 77;
\t\t\tproductRefGroup = {I['grupo-produtos']} /* Products */;
\t\t\tprojectDirPath = "";
\t\t\tprojectRoot = "";
\t\t\ttargets = (
\t\t\t\t{I['alvo-app']} /* BulaIA */,
\t\t\t\t{I['alvo-widget']} /* BulaiWidget */,
\t\t\t);
\t\t}};""")
    o.append("/* End PBXProject section */\n")

    o.append("/* Begin PBXResourcesBuildPhase section */")
    for fase in ("recursos-app", "recursos-widget"):
        o.append(f"""\t\t{I[fase]} /* Resources */ = {{
\t\t\tisa = PBXResourcesBuildPhase;
\t\t\tbuildActionMask = 2147483647;
\t\t\tfiles = (
\t\t\t);
\t\t\trunOnlyForDeploymentPostprocessing = 0;
\t\t}};""")
    o.append("/* End PBXResourcesBuildPhase section */\n")

    o.append("/* Begin PBXSourcesBuildPhase section */")
    for fase in ("fontes-app", "fontes-widget"):
        o.append(f"""\t\t{I[fase]} /* Sources */ = {{
\t\t\tisa = PBXSourcesBuildPhase;
\t\t\tbuildActionMask = 2147483647;
\t\t\tfiles = (
\t\t\t);
\t\t\trunOnlyForDeploymentPostprocessing = 0;
\t\t}};""")
    o.append("/* End PBXSourcesBuildPhase section */\n")

    o.append("/* Begin PBXTargetDependency section */")
    o.append(f"""\t\t{I['dependencia-widget']} /* PBXTargetDependency */ = {{
\t\t\tisa = PBXTargetDependency;
\t\t\ttarget = {I['alvo-widget']} /* BulaiWidget */;
\t\t\ttargetProxy = {I['proxy-widget']} /* PBXContainerItemProxy */;
\t\t}};""")
    o.append("/* End PBXTargetDependency section */\n")

    o.append("/* Begin XCBuildConfiguration section */")
    o.append(configuracao("projeto-debug", "Debug", configuracoes_do_projeto(True), "cfg-debug"))
    o.append(configuracao("projeto-release", "Release", configuracoes_do_projeto(False), "cfg-release"))
    o.append(configuracao("app-debug", "Debug", CONFIG_APP))
    o.append(configuracao("app-release", "Release", CONFIG_APP))
    o.append(configuracao("widget-debug", "Debug", CONFIG_WIDGET))
    o.append(configuracao("widget-release", "Release", CONFIG_WIDGET))
    o.append("/* End XCBuildConfiguration section */\n")

    o.append("/* Begin XCConfigurationList section */")
    for lista, nome, debug, release in (
        ("lista-projeto", 'PBXProject "BulaIA"', "projeto-debug", "projeto-release"),
        ("lista-app", 'PBXNativeTarget "BulaIA"', "app-debug", "app-release"),
        ("lista-widget", 'PBXNativeTarget "BulaiWidget"', "widget-debug", "widget-release"),
    ):
        o.append(f"""\t\t{I[lista]} /* Build configuration list for {nome} */ = {{
\t\t\tisa = XCConfigurationList;
\t\t\tbuildConfigurations = (
\t\t\t\t{I[debug]} /* Debug */,
\t\t\t\t{I[release]} /* Release */,
\t\t\t);
\t\t\tdefaultConfigurationIsVisible = 0;
\t\t\tdefaultConfigurationName = Release;
\t\t}};""")
    o.append("/* End XCConfigurationList section */\n")

    o.append("/* Begin XCLocalSwiftPackageReference section */")
    o.append(f"""\t\t{I['pacote']} /* XCLocalSwiftPackageReference "BulaiCore" */ = {{
\t\t\tisa = XCLocalSwiftPackageReference;
\t\t\trelativePath = BulaiCore;
\t\t}};""")
    o.append("/* End XCLocalSwiftPackageReference section */\n")

    o.append("/* Begin XCSwiftPackageProductDependency section */")
    for dep in ("dep-nucleo-app", "dep-nucleo-widget"):
        o.append(f"""\t\t{I[dep]} /* BulaiCore */ = {{
\t\t\tisa = XCSwiftPackageProductDependency;
\t\t\tpackage = {I['pacote']} /* XCLocalSwiftPackageReference "BulaiCore" */;
\t\t\tproductName = BulaiCore;
\t\t}};""")
    o.append("/* End XCSwiftPackageProductDependency section */")

    corpo = "\n".join(o)
    return f"""// !$*UTF8*$!
{{
\tarchiveVersion = 1;
\tclasses = {{
\t}};
\tobjectVersion = 77;
\tobjects = {{

{corpo}
\t}};
\trootObject = {I['projeto']} /* Project object */;
}}
"""


def esquema():
    app = f"""BuildableIdentifier = "primary"
               BlueprintIdentifier = "{I['alvo-app']}"
               BuildableName = "BulaIA.app"
               BlueprintName = "BulaIA"
               ReferencedContainer = "container:BulaIA.xcodeproj\""""
    return f"""<?xml version="1.0" encoding="UTF-8"?>
<Scheme
   LastUpgradeVersion = "1600"
   version = "1.7">
   <BuildAction
      parallelizeBuildables = "YES"
      buildImplicitDependencies = "YES">
      <BuildActionEntries>
         <BuildActionEntry
            buildForTesting = "YES"
            buildForRunning = "YES"
            buildForProfiling = "YES"
            buildForArchiving = "YES"
            buildForAnalyzing = "YES">
            <BuildableReference
               {app}>
            </BuildableReference>
         </BuildActionEntry>
      </BuildActionEntries>
   </BuildAction>
   <TestAction
      buildConfiguration = "Debug"
      selectedDebuggerIdentifier = "Xcode.DebuggerFoundation.Debugger.LLDB"
      selectedLauncherIdentifier = "Xcode.DebuggerFoundation.Launcher.LLDB"
      shouldUseLaunchSchemeArgsEnv = "YES">
      <Testables>
         <TestableReference
            skipped = "NO">
            <BuildableReference
               BuildableIdentifier = "primary"
               BlueprintIdentifier = "BulaiCoreTests"
               BuildableName = "BulaiCoreTests"
               BlueprintName = "BulaiCoreTests"
               ReferencedContainer = "container:BulaiCore">
            </BuildableReference>
         </TestableReference>
      </Testables>
   </TestAction>
   <LaunchAction
      buildConfiguration = "Debug"
      selectedDebuggerIdentifier = "Xcode.DebuggerFoundation.Debugger.LLDB"
      selectedLauncherIdentifier = "Xcode.DebuggerFoundation.Launcher.LLDB"
      launchStyle = "0"
      useCustomWorkingDirectory = "NO"
      ignoresPersistentStateOnLaunch = "NO"
      debugDocumentVersioning = "YES"
      debugServiceExtension = "internal"
      allowLocationSimulation = "NO">
      <BuildableProductRunnable
         runnableDebuggingMode = "0">
         <BuildableReference
            {app}>
         </BuildableReference>
      </BuildableProductRunnable>
   </LaunchAction>
   <ProfileAction
      buildConfiguration = "Release"
      shouldUseLaunchSchemeArgsEnv = "YES"
      savedToolIdentifier = ""
      useCustomWorkingDirectory = "NO"
      debugDocumentVersioning = "YES">
      <BuildableProductRunnable
         runnableDebuggingMode = "0">
         <BuildableReference
            {app}>
         </BuildableReference>
      </BuildableProductRunnable>
   </ProfileAction>
   <AnalyzeAction
      buildConfiguration = "Debug">
   </AnalyzeAction>
   <ArchiveAction
      buildConfiguration = "Release"
      revealArchiveInOrganizer = "YES">
   </ArchiveAction>
</Scheme>
"""


def main():
    os.makedirs(os.path.join(PROJETO, "xcshareddata", "xcschemes"), exist_ok=True)
    os.makedirs(os.path.join(PROJETO, "project.xcworkspace"), exist_ok=True)
    with open(os.path.join(PROJETO, "project.pbxproj"), "w") as arquivo:
        arquivo.write(projeto())
    with open(os.path.join(PROJETO, "xcshareddata", "xcschemes", "BulaIA.xcscheme"), "w") as arquivo:
        arquivo.write(esquema())
    with open(os.path.join(PROJETO, "project.xcworkspace", "contents.xcworkspacedata"), "w") as arquivo:
        arquivo.write('<?xml version="1.0" encoding="UTF-8"?>\n<Workspace\n   version = "1.0">\n   <FileRef\n      location = "self:">\n   </FileRef>\n</Workspace>\n')
    print("Projeto gerado em", os.path.relpath(PROJETO, os.path.dirname(IOS)))


if __name__ == "__main__":
    main()
