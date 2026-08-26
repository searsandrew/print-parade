using System.Net;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json;

namespace PrintParade.Bridge.Core;

public sealed class BridgeApiClient
{
    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web);
    private readonly HttpClient httpClient;

    public BridgeApiClient(HttpClient httpClient, Uri serverUrl, string token)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(token);

        this.httpClient = httpClient;
        this.httpClient.BaseAddress = new Uri(serverUrl, "/api/bridge/");
        this.httpClient.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", token);
        this.httpClient.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
    }

    public async Task<BridgeHeartbeat> SendHeartbeatAsync(CancellationToken cancellationToken = default)
    {
        using var response = await httpClient.PostAsync("heartbeat", null, cancellationToken);
        response.EnsureSuccessStatusCode();

        return await response.Content.ReadFromJsonAsync<BridgeHeartbeat>(JsonOptions, cancellationToken)
            ?? throw new BridgeProtocolException("The heartbeat response was empty.");
    }

    public async Task<ClaimedPrintJob?> ClaimNextJobAsync(CancellationToken cancellationToken = default)
    {
        using var response = await httpClient.PostAsync("jobs/claim", null, cancellationToken);

        if (response.StatusCode == HttpStatusCode.NoContent)
        {
            return null;
        }

        response.EnsureSuccessStatusCode();

        var job = await response.Content.ReadFromJsonAsync<ClaimedPrintJob>(JsonOptions, cancellationToken)
            ?? throw new BridgeProtocolException("The print job response was empty.");

        if (!job.HasValidChecksum())
        {
            throw new BridgeProtocolException($"Print job {job.JobId} failed checksum verification.");
        }

        return job;
    }

    public async Task MarkJobSpooledAsync(ClaimedPrintJob job, CancellationToken cancellationToken = default)
    {
        using var response = await httpClient.PostAsJsonAsync(
            $"jobs/{job.JobId}/spooled",
            new { claim_token = job.ClaimToken },
            JsonOptions,
            cancellationToken);
        response.EnsureSuccessStatusCode();
    }

    public async Task FailJobAsync(ClaimedPrintJob job, string message, CancellationToken cancellationToken = default)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(message);

        using var response = await httpClient.PostAsJsonAsync(
            $"jobs/{job.JobId}/fail",
            new { claim_token = job.ClaimToken, message },
            JsonOptions,
            cancellationToken);
        response.EnsureSuccessStatusCode();
    }
}
