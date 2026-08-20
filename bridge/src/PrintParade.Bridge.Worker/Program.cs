using PrintParade.Bridge.Core;
using PrintParade.Bridge.Windows;

var serverUrl = Environment.GetEnvironmentVariable("PRINT_PARADE_URL");
var bridgeToken = Environment.GetEnvironmentVariable("PRINT_PARADE_BRIDGE_TOKEN");

if (!Uri.TryCreate(serverUrl, UriKind.Absolute, out var parsedServerUrl) || string.IsNullOrWhiteSpace(bridgeToken))
{
    Console.Error.WriteLine("Set PRINT_PARADE_URL and PRINT_PARADE_BRIDGE_TOKEN before starting the bridge.");
    return 1;
}

if (!OperatingSystem.IsWindows())
{
    Console.Error.WriteLine("The Print Parade bridge worker can spool print jobs only on Windows.");
    return 1;
}

using var shutdown = new CancellationTokenSource();
Console.CancelKeyPress += (_, eventArgs) =>
{
    eventArgs.Cancel = true;
    shutdown.Cancel();
};

using var httpClient = new HttpClient { Timeout = TimeSpan.FromSeconds(30) };
var bridgeClient = new BridgeApiClient(httpClient, parsedServerUrl, bridgeToken);
var processor = new PrintJobProcessor(bridgeClient, new WindowsRawPrintSpooler());

Console.WriteLine("Starting the Print Parade bridge. Press Ctrl+C to stop.");

while (!shutdown.IsCancellationRequested)
{
    try
    {
        var heartbeat = await bridgeClient.SendHeartbeatAsync(shutdown.Token);
        var processedJob = await processor.ProcessNextAsync(shutdown.Token);

        if (processedJob)
        {
            Console.WriteLine($"Bridge {heartbeat.BridgeId} processed a print job.");
        }

        await Task.Delay(TimeSpan.FromSeconds(processedJob ? 0 : 2), shutdown.Token);
    }
    catch (OperationCanceledException) when (shutdown.IsCancellationRequested)
    {
        break;
    }
    catch (Exception exception) when (exception is HttpRequestException or BridgeProtocolException)
    {
        Console.Error.WriteLine($"Print Parade is unavailable: {exception.Message}");
        await DelayAfterFailureAsync(shutdown.Token);
    }
}

Console.WriteLine("Print Parade bridge stopped.");
return 0;

static async Task DelayAfterFailureAsync(CancellationToken cancellationToken)
{
    try
    {
        await Task.Delay(TimeSpan.FromSeconds(5), cancellationToken);
    }
    catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
    {
    }
}
