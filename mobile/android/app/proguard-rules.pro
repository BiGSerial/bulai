# Retrofit, OkHttp e kotlinx.serialization trazem as próprias regras. Os modelos da API (@Serializable) são mantidos
# pelo plugin de serialização; a interface do Retrofit precisa das assinaturas genéricas das funções suspensas.
-keepattributes Signature, InnerClasses, EnclosingMethod, RuntimeVisibleAnnotations, RuntimeVisibleParameterAnnotations
