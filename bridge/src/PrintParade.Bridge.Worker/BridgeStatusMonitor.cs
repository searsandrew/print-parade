namespace PrintParade.Bridge.Worker;

public static class BridgeStatusMonitor
{
    public static async Task RunAsync(CancellationToken cancellationToken = default)
    {
        while (!cancellationToken.IsCancellationRequested)
        {
            var snapshot = await FileBridgeStatusSink.ReadAsync(cancellationToken: cancellationToken);
            Render(snapshot, Console.Out);

            try
            {
                await Task.Delay(TimeSpan.FromSeconds(1), cancellationToken);
            }
            catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
            {
                break;
            }
        }
    }

    public static void Render(BridgeStatusSnapshot? snapshot, TextWriter output)
    {
        if (!Console.IsOutputRedirected)
        {
            Console.Clear();
        }

        output.WriteLine("PRINT PARADE BRIDGE SERVICE MONITOR");
        output.WriteLine(new string('=', 52));

        if (snapshot is null)
        {
            output.WriteLine("No service status has been published yet.");
            output.WriteLine($"Expected status file: {FileBridgeStatusSink.DefaultPath}");
            output.WriteLine("Confirm the service is installed and running.");
        }
        else
        {
            var isStale = snapshot.UpdatedAt < DateTimeOffset.Now.Subtract(TimeSpan.FromSeconds(10));
            output.WriteLine($"Status:          {(isStale ? "STALE — service may not be running" : snapshot.State)}");
            output.WriteLine($"Server:          {snapshot.ServerUrl?.ToString() ?? "—"}");
            output.WriteLine($"Bridge ID:       {snapshot.BridgeId?.ToString() ?? "—"}");
            output.WriteLine($"Last update:     {FormatTime(snapshot.UpdatedAt)}");
            output.WriteLine($"Last poll:       {FormatTime(snapshot.LastPollAt)}");
            output.WriteLine($"Last print job:  {FormatTime(snapshot.LastJobAt)}");
            output.WriteLine($"Jobs processed:  {snapshot.JobsProcessed}");
            output.WriteLine($"Warnings:        {snapshot.WarningCount}");
            output.WriteLine();
            output.WriteLine("RECENT SERVICE ACTIVITY");
            output.WriteLine(new string('-', 52));

            foreach (var entry in snapshot.RecentEvents)
            {
                output.WriteLine(entry);
            }
        }

        output.WriteLine();
        output.WriteLine("This monitor is read-only. Press Ctrl+C to close it.");
    }

    private static string FormatTime(DateTimeOffset? value) => value?.ToString("yyyy-MM-dd HH:mm:ss") ?? "—";
}
