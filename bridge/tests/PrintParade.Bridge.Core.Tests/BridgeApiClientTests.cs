using System.Net;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;

namespace PrintParade.Bridge.Core.Tests;

public sealed class BridgeApiClientTests
{
    [Fact]
    public async Task HeartbeatUsesBearerTokenAndBridgeEndpoint()
    {
        var handler = new RecordingHandler(_ => JsonResponse(new { status = "ok", bridge_id = 42 }));
        var client = CreateClient(handler);

        var heartbeat = await client.SendHeartbeatAsync(CancellationToken.None);

        Assert.Equal(42, heartbeat.BridgeId);
        Assert.Equal("https://print.pacb.online/api/bridge/heartbeat", handler.Request?.RequestUri?.ToString());
        Assert.Equal(new AuthenticationHeaderValue("Bearer", "secret-token"), handler.Request?.Headers.Authorization);
    }

    [Fact]
    public async Task EmptyClaimReturnsNull()
    {
        var handler = new RecordingHandler(_ => new HttpResponseMessage(HttpStatusCode.NoContent));
        var client = CreateClient(handler);

        var job = await client.ClaimNextJobAsync(CancellationToken.None);

        Assert.Null(job);
    }

    [Fact]
    public async Task ClaimRejectsPayloadWithInvalidChecksum()
    {
        var handler = new RecordingHandler(_ => JsonResponse(new
        {
            job_id = "01ABC",
            claim_token = "claim-token",
            lease_expires_at = "2026-08-20T15:00:00Z",
            printer = "packing-zebra-01",
            language = "zpl",
            quantity = 2,
            payload = "^XA^FDTEST^FS^XZ",
            checksum = new string('0', 64),
        }));
        var client = CreateClient(handler);

        var exception = await Assert.ThrowsAsync<BridgeProtocolException>(
            () => client.ClaimNextJobAsync(CancellationToken.None));

        Assert.Contains("checksum", exception.Message, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public async Task CompleteSendsTheClaimToken()
    {
        var handler = new RecordingHandler(_ => JsonResponse(new { status = "completed" }));
        var client = CreateClient(handler);
        var job = new ClaimedPrintJob(
            "01ABC",
            "claim-token",
            DateTimeOffset.Parse("2026-08-20T15:00:00Z"),
            "packing-zebra-01",
            "zpl",
            1,
            "^XA^FDTEST^FS^XZ",
            "8088512094f8e019146eb7207797aec65ee8f13a0507fecd8e0982d9f1306ce7");

        await client.CompleteJobAsync(job, CancellationToken.None);

        Assert.Equal("https://print.pacb.online/api/bridge/jobs/01ABC/complete", handler.Request?.RequestUri?.ToString());
        using var document = JsonDocument.Parse(handler.RequestBody!);
        Assert.Equal("claim-token", document.RootElement.GetProperty("claim_token").GetString());
    }

    private static BridgeApiClient CreateClient(RecordingHandler handler)
    {
        return new BridgeApiClient(
            new HttpClient(handler),
            new Uri("https://print.pacb.online"),
            "secret-token");
    }

    private static HttpResponseMessage JsonResponse(object body)
    {
        return new HttpResponseMessage(HttpStatusCode.OK)
        {
            Content = new StringContent(JsonSerializer.Serialize(body), Encoding.UTF8, "application/json"),
        };
    }

    private sealed class RecordingHandler(Func<HttpRequestMessage, HttpResponseMessage> respond) : HttpMessageHandler
    {
        public HttpRequestMessage? Request { get; private set; }

        public string? RequestBody { get; private set; }

        protected override async Task<HttpResponseMessage> SendAsync(
            HttpRequestMessage request,
            CancellationToken cancellationToken)
        {
            Request = request;
            RequestBody = request.Content is null
                ? null
                : await request.Content.ReadAsStringAsync(cancellationToken);

            return respond(request);
        }
    }
}
