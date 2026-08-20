using PrintParade.Bridge.Core;

var serverUrl = Environment.GetEnvironmentVariable("PRINT_PARADE_URL");
var bridgeToken = Environment.GetEnvironmentVariable("PRINT_PARADE_BRIDGE_TOKEN");

if (!Uri.TryCreate(serverUrl, UriKind.Absolute, out var parsedServerUrl) || string.IsNullOrWhiteSpace(bridgeToken))
{
    Console.Error.WriteLine("Set PRINT_PARADE_URL and PRINT_PARADE_BRIDGE_TOKEN before starting the bridge.");
    return 1;
}

using var httpClient = new HttpClient();
var bridgeClient = new BridgeApiClient(httpClient, parsedServerUrl, bridgeToken);

try
{
    var heartbeat = await bridgeClient.SendHeartbeatAsync();
    Console.WriteLine($"Connected to Print Parade bridge {heartbeat.BridgeId}.");

    return 0;
}
catch (Exception exception) when (exception is HttpRequestException or BridgeProtocolException)
{
    Console.Error.WriteLine($"Unable to connect to Print Parade: {exception.Message}");
    return 1;
}
