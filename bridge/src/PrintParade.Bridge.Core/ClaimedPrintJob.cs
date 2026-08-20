using System.Security.Cryptography;
using System.Text;
using System.Text.Json.Serialization;

namespace PrintParade.Bridge.Core;

public sealed record ClaimedPrintJob(
    [property: JsonPropertyName("job_id")] string JobId,
    [property: JsonPropertyName("claim_token")] string ClaimToken,
    [property: JsonPropertyName("lease_expires_at")] DateTimeOffset LeaseExpiresAt,
    string Printer,
    string Language,
    int Quantity,
    string Payload,
    string Checksum)
{
    public bool HasValidChecksum()
    {
        var payloadHash = SHA256.HashData(Encoding.UTF8.GetBytes(Payload));
        var expectedHash = Convert.FromHexString(Checksum);

        return payloadHash.Length == expectedHash.Length
            && CryptographicOperations.FixedTimeEquals(payloadHash, expectedHash);
    }
}
